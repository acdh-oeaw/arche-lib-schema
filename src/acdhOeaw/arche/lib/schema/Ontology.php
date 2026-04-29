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

namespace acdhOeaw\arche\lib\schema;

use PDO;
use SplObjectStorage;
use OutOfBoundsException;
use RuntimeException;
use rdfInterface\LiteralInterface;
use rdfInterface\DatasetNodeInterface;
use rdfInterface\NamedNodeInterface;
use termTemplates\QuadTemplate as QT;
use termTemplates\PredicateTemplate as PT;
use quickRdf\DataFactory as DF;
use zozlak\RdfConstants as RDF;
use acdhOeaw\arche\lib\Repo;
use acdhOeaw\arche\lib\Schema;
use acdhOeaw\arche\lib\SearchTerm;
use acdhOeaw\arche\lib\SearchConfig;

/**
 * Provides an API for the ARCHE oontology.
 *
 * Maps the RDF ontology structure into the object model.
 * 
 * @author zozlak
 */
class Ontology {

    const VOCABSVALUE_ID        = 1;
    const VOCABSVALUE_NOTATION  = 2;
    const VOCABSVALUE_PREFLABEL = 4;
    const VOCABSVALUE_ALTLABEL  = 8;
    const VOCABSVALUE_ALL       = 255;

    /**
     * 
     * @var array<string, int>
     */
    static private array $vocabsValueProperties = [
        RDF::SKOS_NOTATION   => self::VOCABSVALUE_NOTATION,
        RDF::SKOS_PREF_LABEL => self::VOCABSVALUE_PREFLABEL,
        RDF::SKOS_ALT_LABEL  => self::VOCABSVALUE_ALTLABEL,
    ];

    /**
     * 
     * @param PDO $pdo
     * @param Schema|\stdClass $schema
     * @param string|null $cache File storing ontology cache.
     *   If empty or null, cache isn't used.
     * @param $cacheTtl time in seconds for which the cache file is considered
     *   valid. After that time (or when the cache file doesn't exist) the
     *   cache file is regenerated.
     */
    static public function factoryDb(PDO $pdo, Schema | \stdClass $schema,
                                     ?string $cache = null, int $cacheTtl = 600): self {
        if (!empty($cache) && file_exists($cache) && time() - filemtime($cache) <= $cacheTtl) {
            $ontology      = self::factoryCache($cache);
            $ontology->pdo = $pdo;
            return $ontology;
        }

        $ontology         = new Ontology();
        $ontology->pdo    = $pdo;
        $ontology->schema = $schema instanceof Schema ? $schema : new Schema($schema);
        $ontology->loadClassesDb();
        $ontology->loadPropertiesDb();
        $ontology->loadRestrictionsDb();
        $ontology->preprocess();

        if (!empty($cache) && (!file_exists($cache) || time() - filemtime($cache) > $cacheTtl)) {
            $ontology->saveCache($cache);
        }
        return $ontology;
    }

    static public function factoryRest(string $url, ?string $cache = null,
                                       int $cacheTtl = 600): self {
        if (!empty($cache) && file_exists($cache) && time() - filemtime($cache) <= $cacheTtl) {
            $ontology         = self::factoryCache($cache);
            $ontology->repo   = Repo::factoryFromUrl($url);
            $ontology->schema = $ontology->repo->getSchema();
            return $ontology;
        }

        $ontology         = new Ontology();
        $ontology->repo   = Repo::factoryFromUrl($url);
        $ontology->schema = $ontology->repo->getSchema();
        $ontology->loadRest();
        $ontology->preprocess();

        if (!empty($cache) && (!file_exists($cache) || time() - filemtime($cache) > $cacheTtl)) {
            $ontology->saveCache($cache);
        }
        return $ontology;
    }

    static public function factoryCache(string $cachePath): self {
        $ontology = new Ontology();
        list($ontology->classes, $ontology->classesRev, $ontology->properties, $ontology->distinctProperties, $ontology->restrictions, $ontology->schema) = unserialize(file_get_contents($cachePath) ?: throw new RuntimeException("Failed to load cache file"));
        foreach ($ontology->distinctProperties as $p) {
            $p->setOntologyObject($ontology);
        }
        foreach ($ontology->classes as $c) {
            foreach ($c->properties as $p) {
                $p->setOntologyObject($ontology);
            }
        }
        return $ontology;
    }

    private PDO $pdo;
    private Schema $schema;
    private Repo $repo;

    /**
     *
     * @var array<ClassDesc>
     */
    private array $classes = [];

    /**
     *
     * @var array<string, array<ClassDesc>>
     */
    private array $classesRev = [];

    /**
     *
     * @var array<string, PropertyDesc>
     */
    private array $properties = [];

    /**
     *
     * @var array<string, PropertyDesc>
     */
    private array $distinctProperties = [];

    /**
     *
     * @var array<string, RestrictionDesc>
     */
    private array $restrictions = [];

    public function getNamespace(): string {
        return (string) ($this->schema->ontologyNamespace ?? $this->schema->namespaces->ontology);
    }

    public function saveCache(string $path): void {
        $toSerialize = [
            $this->classes, $this->classesRev,
            $this->properties, $this->distinctProperties,
            $this->restrictions, $this->schema,
        ];
        $output      = tempnam(sys_get_temp_dir(), '') ?: throw new RuntimeException('Failed to create a temporary file');
        $fh          = fopen($output, 'w') ?: throw new RuntimeException('Failed to create a temporary file');
        fwrite($fh, serialize($toSerialize));
        fclose($fh);
        rename($output, $path);
    }

    /**
     * Checks if a given RDF resource is of a given class taking into account
     * ontology class inheritance.
     * 
     * @param DatasetNodeInterface|array<string>|string $resClassOrClassArray
     * @param string|NamedNodeInterface $class
     * @return bool
     */
    public function isA(DatasetNodeInterface | array | string $resClassOrClassArray,
                        string | NamedNodeInterface $class): bool {
        $class = (string) $class;
        if ($resClassOrClassArray instanceof DatasetNodeInterface) {
            $classes = $resClassOrClassArray->listObjects(new PT(DF::namedNode(RDF::RDF_TYPE)))->getValues();
        } elseif (!is_array($resClassOrClassArray)) {
            $classes = [$resClassOrClassArray];
        } else {
            $classes = $resClassOrClassArray;
        }
        foreach ($classes as $t) {
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
     * @param string|NamedNodeInterface $class class name URI
     * @return ClassDesc|null
     */
    public function getClass(string | NamedNodeInterface $class): ?ClassDesc {
        return $this->classes[(string) $class] ?? null;
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
     * and a first encounterred match is returned (property cardinality and 
     * range may vary between classes).
     * 
     * @param DatasetNodeInterface|array<string>|string|null $resClassOrClassArray an RDF resource or an 
     *   array of RDF class URIs or an RDF class URI
     * @param string|NamedNodeInterface $property property URI
     * @return PropertyDesc|null
     */
    public function getProperty(DatasetNodeInterface | array | string | null $resClassOrClassArray,
                                string | NamedNodeInterface $property): ?PropertyDesc {
        $property = (string) $property;
        if ($resClassOrClassArray instanceof DatasetNodeInterface) {
            $classes = $resClassOrClassArray->listObjects(new PT(DF::namedNode(RDF::RDF_TYPE)))->getValues();
        } elseif (!is_array($resClassOrClassArray)) {
            $classes = empty($resClassOrClassArray) ? [] : [$resClassOrClassArray];
        } else {
            $classes = $resClassOrClassArray;
        }
        foreach ($classes as $class) {
            $class = (string) $class;
            if (isset($this->classes[$class]) && isset($this->classes[$class]->properties[$property])) {
                return $this->classes[$class]->properties[$property];
            }
        }
        return $this->properties[$property] ?? null;
    }

    /**
     * Returns all classes known in the ontology.
     * 
     * @return array<ClassDesc>
     */
    public function getClasses(): array {
        $distinct = new SplObjectStorage();
        foreach ($this->classes as $i) {
            $distinct->attach($i);
        }
        return iterator_to_array($distinct);
    }

    /**
     * Returns all classes in the given ontology namespace as an associative
     * array with keys being class URIs in the ontology namespace.
     * 
     * @return array<string, ClassDesc>
     */
    public function getNamespaceClasses(): array {
        $nmsp    = $this->getNamespace();
        $classes = [];
        foreach ($this->classes as $uri => $class) {
            if (str_starts_with($uri, $nmsp)) {
                $classes[$uri] = $class;
            }
        }
        return $classes;
    }

    /**
     * Returns all classes inheriting from a given one.
     * 
     * @param string | NamedNodeInterface | ClassDesc $class
     * @return array<ClassDesc>
     */
    public function getChildClasses(string | NamedNodeInterface | ClassDesc $class): array {
        if ($class instanceof ClassDesc) {
            $class = $class->uri;
        }
        $children = new SplObjectStorage();
        foreach ($this->classesRev[(string) $class] ?? [] as $i) {
            $children->attach($i);
        }
        return iterator_to_array($children);
    }

    /**
     * Returns all properties defined in the ontology.
     * 
     * Property defintions read that way lack cardinality info as the cardinality
     * constraints are defined on the level of property and class.
     * 
     * @return array<PropertyDesc>
     */
    public function getProperties(): array {
        return array_values($this->distinctProperties);
    }

    /**
     * Fetches an array of SkosConceptDesc objects desribing vocabulary values.
     * 
     * @param string $vocabularyUrl
     * @return array<SkosConceptDesc>
     */
    public function getVocabularyValues(string $vocabularyUrl): array {
        if (isset($this->pdo)) {
            $query = "
            SELECT r.id
            FROM
                identifiers i
                JOIN relations r ON r.target_id = i.id AND r.property = ?
            WHERE ids = ?
        ";
            $param = [RDF::SKOS_IN_SCHEME, $vocabularyUrl];
            return $this->fetchVocabularyValuesDb($query, $param);
        } else {
            return $this->fetchVocabularyValuesRest($vocabularyUrl);
        }
    }

    /**
     * Fetches SkosConceptDesc object desribing a vocabulary value.
     * 
     * @param string $vocabularyUrl
     * @param string $value
     * @param int $searchIn combination of Ontology::VOCABSVALUE_* flags indicating
     *   where to search for the $value (in a concept URI/ID, skos:notation, 
     *   skos:prefLabel, etc.)
     * @return ?SkosConceptDesc
     */
    public function getVocabularyValue(string $vocabularyUrl, string $value,
                                       int $searchIn = self::VOCABSVALUE_ID): ?SkosConceptDesc {
        $id = $this->checkVocabularyValue($vocabularyUrl, $value, $searchIn);
        if ($id === false) {
            return null;
        }
        if (isset($this->pdo)) {
            $query = "SELECT id FROM identifiers WHERE ids = ?";
            $param = [$id];
            $value = $this->fetchVocabularyValuesDb($query, $param);
        } else {
            $value = $this->fetchVocabularyValuesRest($vocabularyUrl, $id);
        }
        return array_pop($value);
    }

    /**
     * Checks if a given value exists in a given vocabulary.
     * 
     * @param string $vocabularyUrl
     * @param string $value
     * @param int $searchIn combination of Ontology::VOCABSVALUE_* flags indicating
     *   where to search for the $value (in a concept URI/ID, skos:notation, 
     *   skos:prefLabel, etc.)
     * @return string|false a vocabulary value identifier or false if $value is invalid
     */
    public function checkVocabularyValue(string $vocabularyUrl, string $value,
                                         int $searchIn = self::VOCABSVALUE_ID): string | false {
        if (isset($this->pdo)) {
            return $this->checkVocabularyValueDb($vocabularyUrl, $value, $searchIn);
        } else {
            return $this->checkVocabularyValueRest($vocabularyUrl, $value, $searchIn);
        }
    }

    private function checkVocabularyValueDb(string $vocabularyUrl,
                                            string $value,
                                            int $searchIn = self::VOCABSVALUE_ID): string | false {
        if ($searchIn & self::VOCABSVALUE_ID) {
            $query = "
                SELECT count(*)
                FROM
                    identifiers i1
                    JOIN relations r USING (id)
                    JOIN identifiers i2 ON r.target_id = i2.id
                WHERE
                    i1.ids = ?
                    AND i2.ids = ?
                    AND r.property = ?
            ";
            $param = [$value, $vocabularyUrl, RDF::SKOS_IN_SCHEME];
            $query = $this->pdo->prepare($query);
            $query->execute($param);
            if ($query->fetchColumn() > 0) {
                return $value;
            }
        }
        if ($searchIn <= self::VOCABSVALUE_ID) {
            return false;
        }

        $query = "
            SELECT i1.id, (array_agg(i1.ids))[1] AS ids
            FROM
                identifiers i1
                JOIN metadata m USING (id)
                JOIN relations r USING (id)
                JOIN identifiers i2 ON r.target_id = i2.id
            WHERE
                substring(m.value, 1, 1000) = ?
                AND m.property = ?
                AND i2.ids = ?
                AND r.property = ?
            GROUP BY 1
        ";
        $query = $this->pdo->prepare($query);
        foreach (self::$vocabsValueProperties as $prop => $mask) {
            if ($searchIn & $mask) {
                $query->execute([$value, $prop, $vocabularyUrl, RDF::SKOS_IN_SCHEME]);
                $results = $query->fetchAll(PDO::FETCH_OBJ);
                if (count($results) === 1) {
                    return $results[0]->ids;
                }
            }
        }
        return false;
    }

    private function checkVocabularyValueRest(string $vocabularyUrl,
                                              string $value,
                                              int $searchIn = self::VOCABSVALUE_ID): string | false {
        $allowedProps = [(string) $this->schema->id];
        foreach (self::$vocabsValueProperties as $prop => $mask) {
            if ($searchIn & $mask) {
                $allowedProps[] = $prop;
            }
        }
        $terms                   = [
            new SearchTerm(RDF::SKOS_IN_SCHEME, $vocabularyUrl),
            new SearchTerm($allowedProps, $value),
        ];
        $cfg                     = new SearchConfig();
        $cfg->metadataMode       = '0_0_0_0';
        $cfg->resourceProperties = [(string) $this->schema->id];
        $graph                   = $this->repo->getGraphBySearchTerms($terms, $cfg);
        if (1 === (int) $graph->getObjectValue(new QT(DF::namedNode($this->repo->getBaseUrl()), $this->schema->searchCount))) {
            return $searchIn === self::VOCABSVALUE_ID ? $value : $graph->getObjectValue(new PT($this->schema->id));
        }
        return false;
    }

    private function loadClassesDb(): void {
        $query = "
            WITH RECURSIVE t(pid, id) AS (
                SELECT DISTINCT id, id
                FROM
                    identifiers
                    JOIN metadata USING (id)
                WHERE
                    property = ?
                    AND substring(value, 1, 1000) = ?
              UNION
                SELECT r.target_id, t.id
                FROM
                    relations r 
                    JOIN t ON t.pid = r.id AND property = ? 
            ),
            tt AS (
                SELECT id, jsonb_agg(pid ORDER BY pid) AS pids
                FROM t
                GROUP BY 1
            )
            SELECT id, pids, class, classes, label, comment 
            FROM
                tt
                JOIN (
                    SELECT id, json_agg(DISTINCT ids ORDER BY ids) AS class
                    FROM tt JOIN identifiers USING (id)
                    GROUP BY 1
                ) c1 USING (id)
                JOIN (
                    SELECT t.id, json_agg(DISTINCT ids ORDER BY ids) AS classes 
                    FROM t JOIN identifiers i ON t.pid = i.id
                    GROUP BY 1
                ) c2 USING (id)
                LEFT JOIN (
                    SELECT id, json_object(array_agg(lang), array_agg(value)) AS label
                    FROM tt JOIN metadata USING (id)
                    WHERE property = ?
                    GROUP BY 1
                ) c3 USING (id)
                LEFT JOIN (
                    SELECT id, json_object(array_agg(lang), array_agg(value)) AS comment
                    FROM tt JOIN metadata USING (id)
                    WHERE property = ?
                    GROUP BY 1
                ) c4 USING (id)
        ";
        $param = [
            RDF::RDF_TYPE, RDF::OWL_CLASS, // with non-recursive
            RDF::RDFS_SUB_CLASS_OF, // with recursive
            RDF::SKOS_ALT_LABEL, RDF::RDFS_COMMENT, // c3 (label), c4 (comment)
        ];
        //exit("\n".(new \zozlak\queryPart\QueryPart($query, $param))."\n");
        $query = $this->pdo->prepare($query);
        $query->execute($param);
        while ($c     = $query->fetch(PDO::FETCH_OBJ)) {
            $classList = json_decode($c->class);
            $cc        = new ClassDesc($c, $classList, $this->getNamespace());
            foreach ($classList as $i) {
                $this->classes[$i] = $cc;
            }
            $this->classes[(string) $c->id] = $cc;
        }

        $this->buildClassesRevIndex();
    }

    private function loadPropertiesDb(): void {
        // much faster as it allows "property IN ()" clause in the next query
        $query       = "
            SELECT i1.ids
            FROM
                identifiers i1
                JOIN relations r USING (id)
                JOIN identifiers i2 ON r.target_id = i2.id
            WHERE
                r.property = ?
                AND i2.ids = ?
        ";
        $param       = [$this->schema->parent, RDF::OWL_ANNOTATION_PROPERTY];
        $query       = $this->pdo->prepare($query);
        $query->execute($param);
        $anPropParam = $query->fetchAll(PDO::FETCH_COLUMN);
        $anPropParam = count($anPropParam) > 0 ? $anPropParam : [''];
        $anPropSql   = substr(str_repeat('?, ', count($anPropParam)), 0, -2);

        $query = "
            WITH RECURSIVE t(pid, id, type) AS (
                SELECT DISTINCT id, id, value
                FROM 
                    identifiers
                    JOIN metadata USING (id)
                WHERE 
                    property = ?    
                    AND substring(value, 1, 1000) IN (?, ?)
              UNION
                SELECT r.target_id, t.id, t.type
                FROM
                    relations r 
                    JOIN t ON t.pid = r.id AND property = ?
            ),
            tt AS (
                SELECT id, type, jsonb_agg(pid ORDER BY pid) AS pids
                FROM t
                GROUP BY 1, 2
            )
            SELECT tt.id, tt.pids, tt.type, property, properties, range, domain, label, comment, annotations
            FROM
                tt
                JOIN (
                    SELECT id, json_agg(ids ORDER BY ids) AS property
                    FROM tt JOIN identifiers USING (id)
                    GROUP BY 1
                ) c1 USING (id)
                JOIN (
                    SELECT t.id, jsonb_agg(ids ORDER BY ids) AS properties 
                    FROM t JOIN identifiers i ON t.pid = i.id
                    GROUP BY 1
                ) c2 USING (id)
                JOIN (
                    SELECT r.id, jsonb_agg(ids) AS range
                    FROM relations r JOIN identifiers i ON r.target_id = i.id AND r.property = ?
                    GROUP BY 1
                ) c3 USING (id)
                LEFT JOIN (
                    SELECT r.id, jsonb_agg(ids) AS domain
                    FROM relations r JOIN identifiers i ON r.target_id = i.id AND r.property = ?
                    GROUP BY 1
                ) c4 USING (id)
                LEFT JOIN (
                    SELECT id, json_object(array_agg(lang), array_agg(value)) AS label
                    FROM tt JOIN metadata USING (id)
                    WHERE property = ?
                    GROUP BY 1
                ) c5 USING (id)
                LEFT JOIN (
                    SELECT id, json_object(array_agg(lang), array_agg(value)) AS comment
                    FROM tt JOIN metadata USING (id)
                    WHERE property = ?
                    GROUP BY 1
                ) c6 USING (id)
                LEFT JOIN (
                    SELECT a1.id, json_agg(row_to_json(a1.*)) AS annotations
                    FROM (
                        SELECT id, property, type, lang, value
                        FROM metadata a
                        WHERE property IN ($anPropSql)
                      UNION
                        SELECT r.id, property, 'REL' AS type, null AS lang, ids AS value
                        FROM relations r JOIN identifiers i ON r.target_id = i.id
                        WHERE property IN ($anPropSql)
                    ) a1
                    GROUP BY 1
                ) c7 USING (id)
        ";
        $param = [
            RDF::RDF_TYPE, RDF::OWL_DATATYPE_PROPERTY, RDF::OWL_OBJECT_PROPERTY, // with non-recursive term
            RDF::RDFS_SUB_PROPERTY_OF, // with recursive term
            RDF::RDFS_RANGE, RDF::RDFS_DOMAIN, // c3, c4
            RDF::SKOS_ALT_LABEL, RDF::RDFS_COMMENT, // c5, c6
        ];
        $param = array_merge($param, $anPropParam, $anPropParam);
        $query = $this->pdo->prepare($query);
        $query->execute($param);
        while ($p     = $query->fetch(PDO::FETCH_OBJ)) {
            $propList = json_decode($p->property);
            $prop     = new PropertyDesc($p, $propList, $this->getNamespace());
            $this->loadPropertyCommon($prop);
        }
    }

    private function loadRestrictionsDb(): void {
        $query = "
            SELECT 
                t.id, class, onproperty, 
                coalesce(m3.value, m2.value) AS min,
                coalesce(m4.value, m2.value) AS max
            FROM
                (
                    SELECT i1.id, json_agg(i1.ids ORDER BY i1.ids) AS class
                    FROM 
                        identifiers i1
                        JOIN relations r USING (id)
                        JOIN identifiers i2 ON r.target_id = i2.id AND r.property = ? AND i2.ids = ?
                    GROUP BY 1
                ) t
                LEFT JOIN (
                    SELECT r.id, json_agg(ids) AS onproperty
                    FROM relations r JOIN identifiers i ON r.target_id = i.id AND r.property = ?
                    GROUP BY 1
                ) c1 USING (id)
                LEFT JOIN metadata m2 ON t.id = m2.id AND m2.property = ?
                LEFT JOIN metadata m3 ON t.id = m3.id AND m3.property = ?
                LEFT JOIN metadata m4 ON t.id = m4.id AND m4.property = ?
        ";
        $param = [
            $this->schema->parent, RDF::OWL_RESTRICTION, // t
            RDF::OWL_ON_PROPERTY, // c1
            RDF::OWL_CARDINALITY, RDF::OWL_MIN_CARDINALITY, RDF::OWL_MAX_CARDINALITY, // m2, m3, m4
        ];
        $query = $this->pdo->prepare($query);
        $query->execute($param);
        while ($r     = $query->fetch(PDO::FETCH_OBJ)) {
            $rr = new RestrictionDesc($r);
            $this->loadRestrictionCommon($rr);
        }
    }

    private function loadRest(): void {
        $idPred                  = (string) $this->schema->id;
        $nmsp                    = (string) $this->schema->namespaces->ontology;
        $baseUrl                 = $this->repo->getBaseUrl();
        $baseUrlL                = strlen($baseUrl);
        $mapping                 = [
            RDF::SKOS_ALT_LABEL        => 'label',
            RDF::RDFS_COMMENT          => 'comment',
            RDF::RDFS_SUB_CLASS_OF     => 'parent',
            RDF::RDFS_SUB_PROPERTY_OF  => 'parent',
            RDF::RDFS_RANGE            => 'range',
            RDF::RDFS_DOMAIN           => 'domain',
            RDF::OWL_ON_PROPERTY       => 'onProperty',
            RDF::OWL_CARDINALITY       => 'cardinality',
            RDF::OWL_MIN_CARDINALITY   => 'min',
            RDF::OWL_MAX_CARDINALITY   => 'max',
            $idPred                    => 'ids',
            RDF::RDF_TYPE              => 'type',
            $nmsp . 'automatedFill'    => 'automatedFill',
            $nmsp . 'defaultValue'     => 'defaultValue',
            $nmsp . 'ordering'         => 'ordering',
            $nmsp . 'recommendedClass' => 'recommendedClass',
            $nmsp . 'vocabs'           => 'vocabs',
            $nmsp . 'exampleValue'     => 'exampleValue',
        ];
        $term                    = new SearchTerm(
            RDF::RDF_TYPE,
            [RDF::OWL_CLASS, RDF::OWL_RESTRICTION, RDF::OWL_DATATYPE_PROPERTY, RDF::OWL_OBJECT_PROPERTY]
        );
        $cfg                     = new SearchConfig();
        $cfg->metadataMode       = '0_0_1_0';
        $cfg->resourceProperties = array_keys($mapping);
        $quads                   = $this->repo->getGraphBySearchTerms([$term], $cfg);
        $objects                 = [];
        foreach ($quads as $quad) {
            $sbj  = $quad->getSubject()->getValue();
            $pred = $mapping[$quad->getPredicate()->getValue()] ?? null;
            if ($sbj === $baseUrl || $pred === null) {
                continue;
            }
            $obj = $quad->getObject();
            if (!isset($objects[$sbj])) {
                $objects[$sbj] = (object) ['id' => (int) substr($sbj, $baseUrlL)];
            }
            if (in_array($pred, ['type', 'automatedFill', 'defaultValue',
                    'ordering', 'vocabs', 'min', 'max'])) {
                $objects[$sbj]->$pred = $obj->getValue();
            } elseif ($pred === 'cardinality') {
                $objects[$sbj]->min = $obj->getValue();
                $objects[$sbj]->max = $obj->getValue();
            } elseif ($pred === 'ids') {
                $objects[$sbj]->ids[] = $obj->getValue();
            } elseif ($obj instanceof LiteralInterface) {
                $objects[$sbj]->$pred[$obj->getLang()] = $obj->getValue();
            } else {
                $obj = $obj->getValue();
                if (!isset($objects[$obj])) {
                    $objects[$obj] = (object) ['id' => (int) substr($obj, $baseUrlL)];
                }
                $objects[$sbj]->$pred[] = $objects[$obj];
            }
        }
        $loaded = new SplObjectStorage();
        foreach ($objects as $obj) {
            if ($loaded->offsetExists($obj)) {
                continue;
            }
            $loaded->attach($obj);
            match ($obj->type ?? null) {
                RDF::OWL_CLASS => $this->loadClassRest($obj, $nmsp, $baseUrl),
                RDF::OWL_DATATYPE_PROPERTY, RDF::OWL_OBJECT_PROPERTY => $this->loadPropertyRest($obj, $nmsp),
                RDF::OWL_RESTRICTION => $this->loadRestrictionRest($obj, $nmsp),
                default => null
            };
        }
        $this->buildClassesRevIndex();
    }

    private function loadClassRest(object $data, string $nmsp, string $baseUrl): void {
        $data->class   = $data->ids;
        $data->classes = $data->ids;
        $class         = new ClassDesc($data, $data->ids, $nmsp, $baseUrl);
        $visited       = new SplObjectStorage();
        $visited->attach($data);
        $this->resolveParents($class, 'classes', $data->parent ?? [], $visited);
        sort($class->classes);
        foreach ($class->class as $i) {
            $this->classes[$i] = $class;
        }
    }

    private function loadPropertyRest(object $data, string $nmsp): void {
        $data->property   = $data->ids;
        $data->properties = $data->ids;
        if (!isset($data->range)) {
            return;
        }
        $data->range = array_unique(array_merge(...array_map(fn($x) => $x->ids, $data->range)));
        if (isset($data->domain)) {
            $data->domain = array_unique(array_merge(...array_map(fn($x) => $x->ids, $data->domain)));
        }
        if (isset($data->recommendedClass)) {
            $data->recommendedClass = array_unique(array_merge(...array_map(fn($x) => $x->ids, $data->recommendedClass)));
        }
        $prop    = new PropertyDesc($data, $data->ids, $nmsp);
        $visited = new SplObjectStorage();
        $visited->attach($data);
        $this->resolveParents($prop, 'properties', $data->parent ?? [], $visited);
        sort($prop->properties);
        $this->loadPropertyCommon($prop);
    }

    private function loadRestrictionRest(object $data, string $nmsp): void {
        $data->class = $data->ids;
        if (isset($data->onProperty)) {
            $data->onProperty = array_unique(array_merge(...array_map(fn($x) => $x->ids, $data->onProperty)));
        }
        $restriction = new RestrictionDesc($data, $data->ids, $nmsp);
        $this->loadRestrictionCommon($restriction);
    }

    /**
     * 
     * @param BaseDesc $obj
     * @param string $prop
     * @param array<object> $parents
     * @param SplObjectStorage<object, null> $visited
     * @return void
     */
    private function resolveParents(BaseDesc $obj, string $prop, array $parents,
                                    SplObjectStorage $visited): void {
        foreach ($parents as $parent) {
            if ($visited->offsetExists($parent)) {
                continue;
            }
            $visited->attach($parent);
            $obj->$prop = array_merge($obj->$prop, $parent->ids ?? []);
            $this->resolveParents($obj, $prop, $parent->parent ?? [], $visited);
        }
    }

    private function buildClassesRevIndex(): void {
        // reverse class index (from a class to all inheriting from it)
        foreach ($this->classes as $c) {
            foreach ($c->classes as $cStr) {
                if (!isset($this->classesRev[$cStr])) {
                    $this->classesRev[$cStr] = [];
                }
                $this->classesRev[$cStr][] = $this->classes[$c->class[0]];
            }
        }
    }

    private function loadPropertyCommon(PropertyDesc $prop): void {
        if (!empty($prop->vocabs)) {
            $prop->setOntology($this);
        }
        foreach ($prop->property as $i) {
            $this->properties[(string) $i] = $prop;
        }
        $this->properties[(string) $prop->id]         = $prop;
        $this->distinctProperties[(string) $prop->id] = $prop;
    }

    private function loadRestrictionCommon(RestrictionDesc $restriction): void {
        foreach ($restriction->class as $i) {
            $this->restrictions[(string) $i] = $restriction;
        }
        $this->restrictions[(string) $restriction->id] = $restriction;
    }

    /**
     * Combines class, property and restriction information
     * @return void
     */
    private function preprocess(): void {
        // assign properties to classes
        $classes = array_keys($this->classes);
        foreach ($this->distinctProperties as $p) {
            $classMatch       = array_intersect($p->domain, $classes);
            $processedClasses = new SplObjectStorage();
            foreach ($classMatch as $cid) {
                foreach ($this->classesRev[$cid] as $c) {
                    if (!$processedClasses->offsetExists($c)) {
                        $pp                   = clone($p); // clone because restrictions apply to a {property, class}
                        $pp->recommendedClass = count(array_intersect($c->classes, $p->recommendedClass)) > 0;
                        foreach ($pp->property as $puri) {
                            $c->properties[$puri] = $pp;
                        }
                        $processedClasses->attach($c);
                    }
                }
            }
        }

        // process restrictions
        $restrClasses = array_keys($this->restrictions);
        foreach ($this->classes as $c) {
            $restrMatch     = array_intersect($restrClasses, $c->classes);
            $processedRestr = new SplObjectStorage();
            foreach ($restrMatch as $rid) {
                $r = $this->restrictions[$rid];
                if (!$processedRestr->offsetExists($r) && isset($c->properties[$r->onProperty[0]])) {
                    try {
                        $processedRestr->attach($r);
                        $p = $c->properties[$r->onProperty[0]];
                        if (!empty($r->range)) {
                            $p->range = $r->range;
                        }
                        if (!empty($r->min)) {
                            $p->min = $r->min;
                        }
                        if (!empty($r->max)) {
                            $p->max = $r->max;
                        }
                    } catch (OutOfBoundsException $e) {
                        
                    }
                }
            }
        }
    }

    /**
     * Fetches information about SKOS concepts and formats them as SkosConceptDesc
     * objects.
     * 
     * @param string $valuesQuery
     * @param array<mixed> $valuesParam
     * @return array<SkosConceptDesc>
     */
    private function fetchVocabularyValuesDb(string $valuesQuery,
                                             array $valuesParam): array {
        $query = "
            WITH c AS ($valuesQuery)
            SELECT json_agg(row_to_json(t))
            FROM (
                SELECT *
                FROM
                    c
                    JOIN (
                        SELECT id, json_agg(ids) AS concept
                        FROM identifiers
                        GROUP BY 1
                    ) t0 USING (id)
                    LEFT JOIN (
                        SELECT id, json_agg(value) AS notation
                        FROM metadata
                        WHERE property = ?
                        GROUP BY 1
                    ) t1 USING (id)
                    JOIN (
                        SELECT id, json_agg(json_build_object('lang', lang, 'value', value)) AS label
                        FROM metadata
                        WHERE property = ?
                        GROUP BY 1
                    ) t2 USING (id)
                    LEFT JOIN (
                        SELECT id, json_agg(target_id) AS narrower
                        FROM relations
                        WHERE property = ?
                        GROUP BY 1
                    ) t3 USING (id)
                    LEFT JOIN (
                        SELECT id, json_agg(target_id) AS broader
                        FROM relations
                        WHERE property = ?
                        GROUP BY 1
                    ) t4 USING (id)
            ) t
        ";
        $param = [
            RDF::SKOS_NOTATION,
            $this->schema->label, // t2
            RDF::SKOS_NARROWER, // t3
            RDF::SKOS_BROADER, // t4
        ];
        $query = $this->pdo->prepare($query);
        $query->execute(array_merge($valuesParam, $param));
        $tmp   = json_decode((string) $query->fetchColumn());

        $concepts = [];
        foreach ($tmp as $i) {
            $langs                           = array_map(fn($x) => $x->lang, $i->label);
            $labels                          = array_map(fn($x) => $x->value, $i->label);
            $i->label                        = array_combine($langs, $labels);
            $concept                         = new SkosConceptDesc($i);
            $concepts[(string) $concept->id] = $concept;
        }
        foreach ($concepts as $i) {
            $i->broader  = array_map(fn($x) => $concepts[(string) $x->id], $i->broader);
            $i->narrower = array_map(fn($x) => $concepts[(string) $x->id], $i->narrower);
        }
        $result = [];
        foreach ($concepts as $c) {
            foreach ($c->concept as $i) {
                $result[$i] = $c;
            }
        }
        return $result;
    }

    /**
      /**
     * Fetches information about SKOS concepts and formats them as SkosConceptDesc
     * objects.
     * 
     * @param string $vocabularyUrl
     * @return array<SkosConceptDesc>
     */
    private function fetchVocabularyValuesRest(string $vocabularyUrl,
                                               ?string $conceptUri = null): array {
        $baseUrl  = $this->repo->getBaseUrl();
        $baseUrlL = strlen($baseUrl);
        $nmsp     = (string) $this->schema->namespaces->ontology;
        $mapping  = [
            (string) $this->schema->id    => 'ids',
            (string) $this->schema->label => 'label',
            RDF::SKOS_NOTATION            => 'notation',
            RDF::SKOS_NARROWER            => 'narrower',
            RDF::SKOS_BROADER             => 'broader',
        ];
        $terms    = [new SearchTerm(RDF::SKOS_IN_SCHEME, $vocabularyUrl)];
        if (!empty($conceptUri)) {
            $terms[] = new SearchTerm((string) $this->schema->id, $conceptUri);
        }
        $cfg                     = new SearchConfig();
        $cfg->metadataMode       = '0_0_0_0';
        $cfg->resourceProperties = array_keys($mapping);
        $quads                   = $this->repo->getGraphBySearchTerms($terms, $cfg);
        $objects                 = [];
        foreach ($quads as $quad) {
            $sbj  = $quad->getSubject()->getValue();
            $pred = $mapping[$quad->getPredicate()->getValue()] ?? null;
            if ($sbj === $baseUrl || $pred === null) {
                continue;
            }
            $obj = $quad->getObject();
            if (!isset($objects[$sbj])) {
                $objects[$sbj] = (object) ['id' => (int) substr($sbj, $baseUrlL)];
            }
            if ($pred === 'ids') {
                $objects[$obj->getValue()] = $objects[$sbj];
                $objects[$sbj]->ids[]      = $obj->getValue();
            } elseif ($pred === 'notation') {
                $objects[$sbj]->$pred[] = $obj->getValue();
            } elseif ($obj instanceof LiteralInterface) {
                $objects[$sbj]->$pred[$obj->getLang()] = $obj->getValue();
            } else {
                $obj = $obj->getValue();
                if (!isset($objects[$obj])) {
                    $objects[$obj] = (object) ['id' => (int) substr($obj, $baseUrlL)];
                }
                $objects[$sbj]->$pred[] = $objects[$obj];
            }
        }
        $concepts = [];
        foreach ($objects as $i) {
            $i->concept = $i->ids;
            $concept    = new SkosConceptDesc($i, $i->ids, $nmsp, $baseUrl);
            foreach ($concept->concept as $c) {
                $concepts[$c] = $concept;
            }
        }
        foreach ($concepts as $i) {
            /** @phpstan-ignore instanceof.alwaysTrue */
            $i->broader  = array_map(fn($x) => $x instanceof SkosConceptDesc ? $x : $concepts[reset($x->ids)], $i->broader);
            /** @phpstan-ignore instanceof.alwaysTrue */
            $i->narrower = array_map(fn($x) => $x instanceof SkosConceptDesc ? $x : $concepts[reset($x->ids)], $i->narrower);
        }
        return $concepts;
    }
}
