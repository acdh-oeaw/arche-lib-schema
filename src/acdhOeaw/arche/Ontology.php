<?php

/*
 * The MIT License
 *
 * Copyright 2019 Austrian Centre for Digital Humanities.
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 */

namespace acdhOeaw\arche;

use DateInterval;
use DateTime;
use PDO;
use EasyRdf\Graph;
use EasyRdf\Resource;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;
use zozlak\RdfConstants as RDF;

/**
 * Provides an API for the ARCHE oontology.
 *
 * Maps the RDF ontology structure into the object model.
 * 
 * @author zozlak
 */
class Ontology {

    /**
     *
     * @var ClassDesc[]
     */
    private $classes = [];

    /**
     *
     * @var ClassDesc[]
     */
    private $classesRev = [];

    /**
     *
     * @var PropertyDesc[]
     */
    private $properties = [];

    /**
     *
     * @var RestrictionDesc[]
     */
    private $restrictions = [];

    /**
     * 
     * @param Fedora $fedora repository connection object
     */
    public function __construct(PDO $pdo, object $schema) {
        $this->loadClasses($pdo, $schema->skipNamespace);
        $this->loadProperties($pdo, $schema);
        $this->loadRestrictions($pdo, $schema->skipNamespace);
        $this->preprocess();
    }

    /**
     * Fetches vocabulary definitions provided in the ontology.
     * 
     * Fetching is done in a best-affort way - no error is thrown if a given 
     * vocabulary can't be fetched.
     * 
     * May use a cache.
     * 
     * Be aware fetching and parsing all vocabularies may be time consuming.
     * 
     * @param string $cacheFile cache file to use. If it doesn't exist, it will
     *   be created.
     * @param string $validTime cache validity time in the ISO8601 duration format.
     *   (see https://en.wikipedia.org/wiki/ISO_8601#Durations, e.g. `PT3H` means
     *   3 hours). Be aware default value is 0 seconds meaning effectively no caching.
     * @return void
     */
    public function fetchVocabularies(string $cacheFile = null,
                                      string $validTime = 'PT0S'): void {
        $options = [
            'verify'          => false,
            'http_errors'     => false,
            'allow_redirects' => true,
            'headers'         => ['Accept' => ['text/turtle, application/rdf+xml, application/n-triples']],
        ];
        $client  = new Client($options);

        $cache = (object) [];
        if (!empty($cacheFile) && file_exists($cacheFile)) {
            $timeMod = new DateTime();
            $timeMod->setTimestamp(stat($cacheFile)['mtime']);
            if (new DateTime() <= $timeMod->add(new DateInterval($validTime))) {
                $cache = json_decode(file_get_contents($cacheFile));
                foreach ($cache as $vocabulary) {
                    foreach ($vocabulary as $id => $concept) {
                        $vocabulary->$id = SkosConceptDesc::fromObject($concept);
                    }
                }
            }
        }

        foreach ($this->classes as $class) {
            foreach ($class->properties as $prop) {
                if (empty($prop->vocabs)) {
                    continue;
                }
                if (!isset($cache->{$prop->vocabs}) || $cache->{$prop->vocabs} === null) {
                    $resp = $client->send(new Request('GET', $prop->vocabs));
                    if ($resp->getStatusCode() !== 200) {
                        continue;
                    }
                    $body  = (string) $resp->getBody();
                    $graph = new Graph();
                    $graph->parse($body, $resp->getHeader('Content-Type')[0] ?? null);

                    $cache->{$prop->vocabs} = [];
                    foreach ($graph->allOfType(RDF::SKOS_CONCEPT) as $concept) {
                        $cache->{$prop->vocabs}[$concept->getUri()] = SkosConceptDesc::fromResource($concept);
                    }
                }
                $prop->vocabsValues = ((array) $cache->{$prop->vocabs}) ?? null;
            }
        }

        if (!empty($cacheFile)) {
            file_put_contents($cacheFile, json_encode($cache));
        }
    }

    /**
     * Checks if a given RDF resource is of a given class taking into account
     * ontology class inheritance.
     * 
     * @param Resource $res
     * @param string $class
     * @return bool
     */
    public function isA(Resource $res, string $class): bool {
        foreach ($res->allResources(RDF::RDF_TYPE) as $t) {
            $t = (string) $t;
            if ($t === $class) {
                return true;
            }
            if (isset($this->classes[$t]) && in_array($class, $this->classes[$t]->classes)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Returns class description.
     * 
     * @param string $class class name URI
     * @return \acdhOeaw\arche\ClassDesc|null
     */
    public function getClass(string $class): ?ClassDesc {
        return $this->classes[$class] ?? null;
    }

    /**
     * Returns a given property description for a given set of RDF classes or
     * an RDF resource (in the latter case classes list is extracted from the
     * resource).
     * 
     * If property domain matches many classes, a description for first
     * encountered class is returned (property cardinality and range may vary
     * between classes).
     * 
     * If no classes/RDF resource is provided all known classes are searched
     * and a first encounterred match is returned (property cardinality and range may vary
     * between classes).
     * 
     * @param $resOrClassesArray \EasyRdf\Resource RDF resource or an array of 
     *   RDF class URIs or an RDF class URI
     * @param string $property property URI
     * @return \acdhOeaw\arche\PropertyDesc|null
     */
    public function getProperty($resOrClassesArray, string $property): ?PropertyDesc {
        if (empty($resOrClassesArray)) {
            $resOrClassesArray = array_keys($this->classes);
        } elseif ($resOrClassesArray instanceof Resource) {
            $resOrClassesArray = $resOrClassesArray->allResources(RDF::RDF_TYPE);
        } elseif (!is_array($resOrClassesArray)) {
            $resOrClassesArray = [$resOrClassesArray];
        }
        foreach ($resOrClassesArray as $class) {
            $class = (string) $class;
            if (isset($this->classes[$class]) && isset($this->classes[$class]->properties[$property])) {
                return $this->classes[$class]->properties[$property];
            }
        }
        return $this->properties[$property] ?? null;
    }

    private function loadClasses(PDO $pdo, string $nmspSkip): void {
        $query = "
            WITH RECURSIVE t(sid, id, n) AS (
                SELECT DISTINCT id, id, 0
                FROM
                    identifiers
                    JOIN metadata USING (id)
                WHERE
                    ids NOT LIKE ?
                    AND property = ?
                    AND value = ?
              UNION
                SELECT r.target_id, t.id, t.n + 1
                FROM
                    relations r 
                    JOIN t ON t.sid = r.id AND property = ?
            )
            SELECT c1.id, class, classes, label, comment
            FROM
                (
                    SELECT t.id, i1.ids AS class, json_agg(i2.ids ORDER BY n DESC) AS classes 
                    FROM 
                        t 
                        JOIN identifiers i1 ON t.id = i1.id AND i1.ids NOT LIKE ?
                        JOIN identifiers i2 ON t.sid = i2.id AND i2.ids NOT LIKE ?
                    GROUP BY 1, 2
                ) c1
                LEFT JOIN (
                    SELECT id, json_object(array_agg(m2.lang), array_agg(m2.value)) AS label
                    FROM 
                        metadata m1
                        JOIN metadata m2 USING (id)
                    WHERE
                        m1.property = ?
                        AND substring(m1.value, 1, 1000) = ?
                        AND m2.property = ?
                    GROUP BY 1
                ) c2 USING (id)
                LEFT JOIN (
                    SELECT id, json_object(array_agg(m2.lang), array_agg(m2.value)) AS comment
                    FROM 
                        metadata m1
                        JOIN metadata m2 USING (id)
                    WHERE
                        m1.property = ?
                        AND substring(m1.value, 1, 1000) = ?
                        AND m2.property = ?
                    GROUP BY 1
                ) c3 USING (id)
        ";
        $param = [
            $nmspSkip, RDF::RDF_TYPE, RDF::OWL_CLASS, RDF::RDFS_SUB_CLASS_OF, // with
            $nmspSkip, $nmspSkip, // normal query - classes
            RDF::RDF_TYPE, RDF::OWL_CLASS, RDF::SKOS_ALT_LABEL, // normal query - label
            RDF::RDF_TYPE, RDF::OWL_CLASS, RDF::RDFS_COMMENT, // normal query - comment
        ];
        $query = $pdo->prepare($query);
        $query->execute($param);
        while ($c     = $query->fetch(PDO::FETCH_OBJ)) {
            $this->classes[$c->class] = new ClassDesc($c);
        }

        // reverse class index (from a class to all inheriting from it)
        foreach ($this->classes as $c) {
            foreach ($c->classes as $cStr) {
                if (!isset($this->classesRev[$cStr])) {
                    $this->classesRev[$cStr] = [];
                }
                $this->classesRev[$cStr][] = $this->classes[$c->class];
            }
        }
    }

    private function loadProperties(PDO $pdo, object $schema): void {
        $nmspSkip = $schema->skipNamespace;
        // slightly more complex to deal with rdfs:range and rdfs:domain no matter if they are stored as relations or literals-like
        $query    = "
            WITH RECURSIVE t(sid, id, type, n) AS (
                SELECT DISTINCT id, id, value, 0
                FROM 
                    identifiers
                    JOIN metadata USING (id)
                WHERE 
                    ids LIKE ?
                    AND property = ?
                    AND substring(value, 1, 1000) IN (?, ?)
              UNION
                SELECT r.target_id, t.id, t.type, t.n + 1
                FROM
                    relations r 
                    JOIN t ON t.sid = r.id AND property = ?
            )
            SELECT id, property, type, range, domain, \"order\", properties, label, comment, recommended, langtag, vocabs
            FROM
                (
                    SELECT 
                        t.id, 
                        i1.ids AS property, 
                        t.type, 
                        coalesce(m3.value, i3.ids) AS range, 
                        coalesce(m4.value, i4.ids) AS domain, 
                        m5.value_n AS \"order\",
                        m6.value AS langtag,
                        m7.value AS vocabs,
                        json_agg(i2.ids ORDER BY n DESC) AS properties 
                    FROM 
                        t 
                        JOIN identifiers i1 ON t.id = i1.id AND i1.ids NOT LIKE ?
                        JOIN identifiers i2 ON t.sid = i2.id AND i2.ids NOT LIKE ?
                        LEFT JOIN metadata m3 ON t.id = m3.id AND m3.property = ?
                        LEFT JOIN relations r3 ON t.id = r3.id AND r3.property = ?
                        LEFT JOIN identifiers i3 ON r3.target_id = i3.id AND i3.ids NOT LIKE ?
                        LEFT JOIN metadata m4 ON t.id = m4.id AND m4.property = ?
                        LEFT JOIN relations r4 ON t.id = r4.id AND r4.property = ?
                        LEFT JOIN identifiers i4 ON r4.target_id = i4.id AND i4.ids NOT LIKE ?
                        LEFT JOIN metadata m5 ON t.id = m5.id AND m5.property = ?
                        LEFT JOIN metadata m6 ON t.id = m6.id AND m6.property = ?
                        LEFT JOIN metadata m7 ON t.id = m7.id AND m7.property = ?
                    GROUP BY 1, 2, 3, 4, 5, 6, 7, 8
                ) t1
                LEFT JOIN (
                    SELECT id, json_object(array_agg(l2.lang), array_agg(l2.value)) AS label
                    FROM 
                        metadata l1
                        JOIN metadata l2 USING (id)
                    WHERE
                        l1.property = ?
                        AND substring(l1.value, 1, 1000) IN (?, ?)
                        AND l2.property = ?
                    GROUP BY 1
                ) t2 USING (id)
                LEFT JOIN (
                    SELECT id, json_object(array_agg(c2.lang), array_agg(c2.value)) AS comment
                    FROM 
                        metadata c1
                        JOIN metadata c2 USING (id)
                    WHERE
                        c1.property = ?
                        AND substring(c1.value, 1, 1000) IN (?, ?)
                        AND c2.property = ?
                    GROUP BY 1
                ) t3 USING (id)
                LEFT JOIN (
                    SELECT id, json_agg(r1.value) AS recommended
                    FROM metadata r1
                    WHERE r1.property = ?
                    GROUP BY 1
                ) t4 USING (id)
        ";
        $param    = [
            $nmspSkip, RDF::RDF_TYPE, RDF::OWL_DATATYPE_PROPERTY, RDF::OWL_OBJECT_PROPERTY, // with non-recursive term
            RDF::RDFS_SUB_PROPERTY_OF, // with recursive term
            $nmspSkip, $nmspSkip, // i1, i2
            RDF::RDFS_RANGE, RDF::RDFS_RANGE, $nmspSkip, // m3, r3, i3
            RDF::RDFS_DOMAIN, RDF::RDFS_DOMAIN, $nmspSkip, // m4, r4, i4
            $schema->order, $schema->langTag, $schema->vocabs, // m5, m6, m7
            RDF::RDF_TYPE, RDF::OWL_DATATYPE_PROPERTY, RDF::OWL_OBJECT_PROPERTY,
            RDF::SKOS_ALT_LABEL, // l1, l2
            RDF::RDF_TYPE, RDF::OWL_DATATYPE_PROPERTY, RDF::OWL_OBJECT_PROPERTY,
            RDF::RDFS_COMMENT, // c1, c2
            $schema->recommended, // r1
        ];
        $query    = $pdo->prepare($query);
        $query->execute($param);
        while ($p        = $query->fetch(PDO::FETCH_OBJ)) {
            $this->properties[$p->property] = new PropertyDesc($p);
        }
    }

    private function loadRestrictions(PDO $pdo, string $nmspSkip): void {
        // slightly more complex to deal with owl:onDataRange and owl:onClass no matter if they are stored as relations or literals-like
        $query = "
            SELECT 
                t.id, 
                t.ids AS class,
                coalesce(m1.value, i1.ids) AS onProperty,
                coalesce(m2.value, i2.ids, m3.value, i3.ids) AS range,
                coalesce(m8.value, m5.value, m7.value, m4.value) AS min,
                coalesce(m9.value, m6.value, m7.value, m4.value) AS max
            FROM
                (
                    SELECT id, ids
                    FROM 
                        identifiers
                        JOIN metadata USING (id)
                    WHERE 
                        ids NOT LIKE ?
                        AND property = ?
                        AND value = ?
                ) t
                LEFT JOIN metadata m1 ON t.id = m1.id AND m1.property = ?
                LEFT JOIN relations r1 ON t.id = r1.id AND r1.property = ?
                LEFT JOIN identifiers i1 ON r1.target_id = i1.id AND i1.ids NOT LIKE ?
                LEFT JOIN metadata m2 ON t.id = m2.id AND m2.property = ?
                LEFT JOIN relations r2 ON t.id = r2.id AND r2.property = ?
                LEFT JOIN identifiers i2 ON r2.target_id = i2.id AND i2.ids NOT LIKE ?
                LEFT JOIN metadata m3 ON t.id = m3.id AND m3.property = ?
                LEFT JOIN relations r3 ON t.id = r3.id AND r3.property = ?
                LEFT JOIN identifiers i3 ON r3.target_id = i3.id AND i3.ids NOT LIKE ?
                LEFT JOIN metadata m4 ON t.id = m4.id AND m4.property = ?
                LEFT JOIN metadata m5 ON t.id = m5.id AND m5.property = ?
                LEFT JOIN metadata m6 ON t.id = m6.id AND m6.property = ?
                LEFT JOIN metadata m7 ON t.id = m7.id AND m7.property = ?
                LEFT JOIN metadata m8 ON t.id = m8.id AND m8.property = ?            
                LEFT JOIN metadata m9 ON t.id = m9.id AND m9.property = ?
        ";
        $param = [
            $nmspSkip, RDF::RDF_TYPE, RDF::OWL_RESTRICTION,
            RDF::OWL_ON_PROPERTY, RDF::OWL_ON_PROPERTY, $nmspSkip, // m1, r1, i1
            RDF::OWL_ON_CLASS, RDF::OWL_ON_CLASS, $nmspSkip, // m2, r2, i2
            RDF::OWL_ON_DATA_RANGE, RDF::OWL_ON_DATA_RANGE, $nmspSkip, // m3, r3, i3
            RDF::OWL_CARDINALITY, RDF::OWL_MIN_CARDINALITY, RDF::OWL_MAX_CARDINALITY, // m4, m5, m6
            RDF::OWL_QUALIFIED_CARDINALITY, RDF::OWL_MIN_QUALIFIED_CARDINALITY, RDF::OWL_MAX_QUALIFIED_CARDINALITY, // m7, m8, m9
        ];
        $query = $pdo->prepare($query);
        $query->execute($param);
        while ($r     = $query->fetch(PDO::FETCH_OBJ)) {
            $this->restrictions[$r->class] = new RestrictionDesc($r);
        }
    }

    /**
     * Combines class, property and restriction information
     * @return void
     */
    private function preprocess(): void {
        // inherit property range
        foreach ($this->properties as $p) {
            for ($i = 1; empty($this->range) && $i < count($p->properties); $i++) {
                $this->property = $this->properties[$p->properties[$i]]->range;
            }
        }

        // assign properties to classes
        foreach ($this->properties as $p) {
            foreach ($this->classesRev[$p->domain] ?? [] as $c) {
                $pp                          = clone($p); // clone because restrictions apply to a {property, class}
                $pp->recommended             = count(array_intersect($c->classes, $p->recommended)) > 0;
                $c->properties[$p->property] = $pp;
            }
        }

        // process restrictions
        foreach ($this->classes as $c) {
            foreach ($c->classes as $cStr) {
                if (!isset($this->restrictions[$cStr])) {
                    continue;
                }
                $r = $this->restrictions[$cStr];
                if (!isset($c->properties[$r->onProperty])) {
                    continue;
                }
                $p = $c->properties[$r->onProperty];

                if (!empty($r->range)) {
                    $p->range = $r->range;
                }
                if (!empty($r->min)) {
                    $p->min = $r->min;
                }
                if (!empty($r->max)) {
                    $p->max = $r->max;
                }
            }
        }
    }

}
