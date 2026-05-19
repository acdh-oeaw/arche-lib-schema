<?php

/*
 * The MIT License
 *
 * Copyright 2019 zozlak.
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
use ReflectionClass;
use ReflectionProperty;
use quickRdf\DataFactory as DF;
use quickRdf\DatasetNode;
use zozlak\RdfConstants as RDF;

/**
 * Description of OntologyTest
 *
 * @author zozlak
 */
class OntologyTest extends \PHPUnit\Framework\TestCase {

    const CACHE_FILE = 'cache';

    /**
     *
     * @var \PDO
     */
    static private $pdo;

    /**
     *
     * @var object
     */
    static private $schema;

    static public function setUpBeforeClass(): void {
        self::$pdo = new PDO('pgsql: host=localhost port=5432 user=www-data');
        self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        self::$schema = (object) [
                'ontologyNamespace' => 'https://vocabs.acdh.oeaw.ac.at/schema#',
                'parent'            => 'https://vocabs.acdh.oeaw.ac.at/schema#isPartOf',
                'label'             => 'https://vocabs.acdh.oeaw.ac.at/schema#hasTitle',
        ];
    }

    public function tearDown(): void {
        if (file_exists(self::CACHE_FILE)) {
            unlink(self::CACHE_FILE);
        }
    }

    /**
     * 
     * @return array<Ontology>
     */
    private function getOntologies(): array {
        return [
            'db'    => Ontology::factoryDb(self::$pdo, self::$schema),
            'rest'  => Ontology::factoryRest('http://127.0.0.1/api'),
            'arche' => Ontology::factoryRest('https://arche.acdh.oeaw.ac.at/api'),
        ];
    }

    public function testInit(): void {
        foreach ($this->getOntologies() as $k => $o) {
            /** @phpstan-ignore method.alreadyNarrowedType */
            $this->assertNotNull($o, $k);
        }
    }

    public function testClassLabelComment(): void {
        foreach ($this->getOntologies() as $k => $o) {
            $c = $o->getClass('https://vocabs.acdh.oeaw.ac.at/schema#Collection');
            $this->assertArrayHasKey('en', $c->label, $k);
            $this->assertArrayHasKey('de', $c->label, $k);
            $this->assertArrayHasKey('en', $c->comment, $k);
            $this->assertArrayHasKey('de', $c->comment, $k);
            $this->assertEquals('Collection', $c->label['en'], $k);
            $this->assertEquals($c->label['en'], $c->getLabel('en'), $k);
            $this->assertEquals($c->label['de'], $c->getLabel('de'), $k);
            $this->assertEquals($c->comment['en'], $c->getComment('en'), $k);
            $this->assertEquals($c->comment['de'], $c->getComment('de'), $k);
        }
    }

    public function testClassInheritance(): void {
        $sbj  = DF::namedNode('.');
        $pred = DF::namedNode(RDF::RDF_TYPE);
        foreach ($this->getOntologies() as $k => $o) {
            $r1 = new DatasetNode($sbj);
            $r1->add(DF::quad($sbj, $pred, DF::namedNode('https://vocabs.acdh.oeaw.ac.at/schema#Collection')));
            $this->assertTrue($o->isA($r1, 'https://vocabs.acdh.oeaw.ac.at/schema#RepoObject'), $k);

            $r2 = new DatasetNode(DF::namedNode('.'));
            $r2->add(DF::quad($sbj, $pred, DF::namedNode('https://vocabs.acdh.oeaw.ac.at/schema#BinaryContent')));
            $this->assertTrue($o->isA($r2, 'https://vocabs.acdh.oeaw.ac.at/schema#RepoObject'), $k);

            $r3 = new DatasetNode(DF::namedNode('.'));
            $r3->add(DF::quad($sbj, $pred, DF::namedNode('https://vocabs.acdh.oeaw.ac.at/schema#Agent')));
            $this->assertFalse($o->isA($r3, 'https://vocabs.acdh.oeaw.ac.at/schema#RepoObject'), $k);

            $r3->add(DF::quad($sbj, $pred, DF::namedNode('https://vocabs.acdh.oeaw.ac.at/schema#RepoObject')));
            $this->assertTrue($o->isA($r3, 'https://vocabs.acdh.oeaw.ac.at/schema#RepoObject'), $k);
        }
    }

    public function testClassGetProperties(): void {
        $n1 = $n2 = $n3 = null;
        foreach ($this->getOntologies() as $k => $o) {
            $c  = $o->getClass('https://vocabs.acdh.oeaw.ac.at/schema#Collection');
            $p  = $c->getProperties();
            $n1 ??= count($p);
            $this->assertGreaterThan(0, count($p), $k);
            $this->assertEquals($n1, count($p), $k);
            $this->assertEquals(count(array_unique(array_map('spl_object_id', $p))), count($p), $k);
            $this->assertEquals(count(array_unique(array_map('spl_object_id', $c->properties))), count($p), $k);

            // https://vocabs.acdh.oeaw.ac.at/schema#Project and http://xmlns.com/foaf/0.1/Project are owl:equivalentClass
            // which caused a lot of trouble in the past
            $c  = $o->getClass('https://vocabs.acdh.oeaw.ac.at/schema#Project');
            $p  = $c->getProperties();
            $n2 ??= count($p);
            $this->assertGreaterThan(0, count($p), $k);
            $this->assertEquals($n2, count($p), $k);
            $this->assertEquals(count(array_unique(array_map('spl_object_id', $p))), count($p), $k);
            $this->assertEquals(count(array_unique(array_map('spl_object_id', $c->properties))), count($p), $k);

            $c2 = $o->getClass('http://xmlns.com/foaf/0.1/Project');
            $this->assertEmpty(array_intersect($c->class, $c2->class));
            $p  = $c2->getProperties();
            $n3 ??= count($p);
            $this->assertGreaterThan(0, count($p), $k);
            $this->assertEquals($n3, count($p), $k);
            $this->assertEquals(count(array_unique(array_map('spl_object_id', $p))), count($p), $k);
            $this->assertEquals(count(array_unique(array_map('spl_object_id', $c2->properties))), count($p), $k);
        }
    }

    public function testPropertyPropertiesInitialized(): void {
        foreach ($this->getOntologies() as $k => $o) {
            $p  = $o->getProperty(null, 'https://vocabs.acdh.oeaw.ac.at/schema#hasLicense');
            $rc = new ReflectionClass($p);
            foreach ($rc->getProperties(ReflectionProperty::IS_PUBLIC) as $i) {
                $rp = new ReflectionProperty($p, $i->name);
                $rt = $rp->getType();
                if ($rt !== null && !$rt->allowsNull()) {
                    $this->assertTrue(isset($p->{$i->name}), "propertyDesc->" . $i->name . " is not initialized ($k)");
                }
            }
        }
    }

    public function testCardinalitiesIndirect(): void {
        foreach ($this->getOntologies() as $k => $o) {
            $c    = $o->getClass('https://vocabs.acdh.oeaw.ac.at/schema#Collection');
            $pUri = 'https://vocabs.acdh.oeaw.ac.at/schema#hasDepositor'; //defined for RepoObject
            $this->assertArrayHasKey($pUri, $c->properties, $k);
            $this->assertEquals(1, $c->properties[$pUri]->min, $k);

            $c    = $o->getClass('https://vocabs.acdh.oeaw.ac.at/schema#BinaryContent');
            $pUri = 'https://vocabs.acdh.oeaw.ac.at/schema#hasNote';
            $this->assertArrayHasKey($pUri, $c->properties, $k);
            $this->assertNull($c->properties[$pUri]->min, $k);
        }
    }

    public function testCardinalitiesDirect(): void {
        $sbj  = DF::namedNode('.');
        $pred = DF::namedNode(RDF::RDF_TYPE);
        foreach ($this->getOntologies() as $k => $o) {
            $r1 = new DatasetNode($sbj);
            $r1->add(DF::quad($sbj, $pred, DF::namedNode('https://vocabs.acdh.oeaw.ac.at/schema#TopCollection')));
            $p1 = $o->getProperty($r1, 'https://vocabs.acdh.oeaw.ac.at/schema#hasContact');
            $this->assertEquals(1, $p1->min, $k);

            $r2 = new DatasetNode($sbj);
            $r2->add(DF::quad($sbj, $pred, DF::namedNode('https://vocabs.acdh.oeaw.ac.at/schema#BinaryContent')));
            $p2 = $o->getProperty($r2, 'https://vocabs.acdh.oeaw.ac.at/schema#hasContact');
            $this->assertNull($p2->min, $k);

            $this->assertNull($o->getProperty($r2, 'https://foo/bar'), $k);
        }
    }

    public function testPropertyDomainRange(): void {
        foreach ($this->getOntologies() as $k => $o) {
            $p = $o->getProperty(null, 'https://vocabs.acdh.oeaw.ac.at/schema#hasAcceptedDate');
            $this->assertContains('http://www.w3.org/2001/XMLSchema#date', $p->range, $k);
            $this->assertContains('https://vocabs.acdh.oeaw.ac.at/schema#RepoObject', $p->domain, $k);
        }
    }

    public function testPropertyLabelComment(): void {
        foreach ($this->getOntologies() as $k => $o) {
            $c = $o->getClass('https://vocabs.acdh.oeaw.ac.at/schema#Collection');
            $p = $c->properties['https://vocabs.acdh.oeaw.ac.at/schema#hasContact'];
            $this->assertArrayHasKey('en', $p->label, $k);
            $this->assertArrayHasKey('de', $p->label, $k);
            $this->assertArrayHasKey('en', $p->comment, $k);
            $this->assertEquals('Contact(s)', $p->label['en'], $k);
        }
    }

    public function testPropertyLangTag(): void {
        foreach ($this->getOntologies() as $k => $o) {
            $c = $o->getClass('https://vocabs.acdh.oeaw.ac.at/schema#Collection');
            $p = $c->properties['https://vocabs.acdh.oeaw.ac.at/schema#hasTitle'];
            $this->assertEquals(1, $p->langTag, $k);
        }
    }

    public function testPropertyVocabs(): void {
        foreach ($this->getOntologies() as $k => $o) {
            $c = $o->getClass('https://vocabs.acdh.oeaw.ac.at/schema#Collection');
            $p = $c->properties['https://vocabs.acdh.oeaw.ac.at/schema#hasLicense'];
            $this->assertEquals('https://vocabs.acdh.oeaw.ac.at/rest/v1/arche_licenses/data', $p->vocabs, $k);
        }
    }

    public function testPropertyVocabularyValues(): void {
        foreach ($this->getOntologies() as $k => $o) {
            $c = $o->getClass('https://vocabs.acdh.oeaw.ac.at/schema#Collection');
            $p = $c->properties['https://vocabs.acdh.oeaw.ac.at/schema#hasLicense'];
            /** @phpstan-ignore property.private */
            $this->assertArrayHasKey('https://vocabs.acdh.oeaw.ac.at/archelicenses/cc-by-4-0', $p->vocabularyValues, $k);
            /** @phpstan-ignore property.private */
            $this->assertEquals('CC BY 4.0', $p->vocabularyValues['https://vocabs.acdh.oeaw.ac.at/archelicenses/cc-by-4-0']->getLabel('en'), $k);
            /** @phpstan-ignore property.private */
            $this->assertEquals('CC BY 4.0', $p->vocabularyValues['https://vocabs.acdh.oeaw.ac.at/archelicenses/cc-by-4-0']->getLabel('pl', 'en'), $k);

            $c       = $o->getClass('https://vocabs.acdh.oeaw.ac.at/schema#Resource');
            $p       = $c->properties['https://vocabs.acdh.oeaw.ac.at/schema#hasCategory'];
            /** @phpstan-ignore property.private */
            $this->assertArrayHasKey('https://vocabs.acdh.oeaw.ac.at/archecategory/image', $p->vocabularyValues, $k);
            /** @phpstan-ignore property.private */
            $concept = $p->vocabularyValues['https://vocabs.acdh.oeaw.ac.at/archecategory/image'];
            $this->assertInstanceOf(SkosConceptDesc::class, $concept->narrower[0]);
            $this->assertEquals($concept, $concept->narrower[0]->broader[0], $k);

            $c = $o->getClass('https://vocabs.acdh.oeaw.ac.at/schema#Project');
            $p = $c->properties['https://vocabs.acdh.oeaw.ac.at/schema#hasRelatedDiscipline'];
            /** @phpstan-ignore property.private */
            $this->assertArrayHasKey('https://vocabs.acdh.oeaw.ac.at/oefosdisciplines/102003', $p->vocabularyValues);
            /** @phpstan-ignore property.private */
            $this->assertEquals(['102003'], $p->vocabularyValues['https://vocabs.acdh.oeaw.ac.at/oefosdisciplines/102003']->notation);
        }
    }

    public function testPropertyGetVocabularyValues(): void {
        foreach ($this->getOntologies() as $k => $o) {
            $c  = $o->getClass('https://vocabs.acdh.oeaw.ac.at/schema#Collection');
            $p  = $c->properties['https://vocabs.acdh.oeaw.ac.at/schema#hasLicense'];
            $vv = $p->getVocabularyValues();
            $this->assertEquals(count(array_unique(array_map('spl_object_id', $vv))), count($vv), $k);
            /** @phpstan-ignore property.private */
            $this->assertEquals(count(array_unique(array_map('spl_object_id', $p->vocabularyValues))), count($vv), $k);
        }
    }

    public function testPropertyCheckVocabularyValue(): void {
        foreach ($this->getOntologies() as $k => $o) {
            $c = $o->getClass('https://vocabs.acdh.oeaw.ac.at/schema#Collection');
            $p = $c->properties['https://vocabs.acdh.oeaw.ac.at/schema#hasLicense'];
            $this->assertIsString($p->checkVocabularyValue('https://vocabs.acdh.oeaw.ac.at/archelicenses/publicdomain-1-0'), $k);
            $this->assertFalse($p->checkVocabularyValue('Public Domain Mark 1.0'), $k);
            $this->assertIsString($p->checkVocabularyValue('Public Domain Mark 1.0', Ontology::VOCABSVALUE_PREFLABEL), $k);
            $this->assertFalse($p->checkVocabularyValue('foo', Ontology::VOCABSVALUE_ALL), $k);

            $p = $c->properties['https://vocabs.acdh.oeaw.ac.at/schema#hasTitle'];
            $this->assertTrue($p->checkVocabularyValue('foo'), $k);
        }
    }

    public function testPropertyGetVocabularyValue(): void {
        foreach ($this->getOntologies() as $k => $o) {
            $c = $o->getClass('https://vocabs.acdh.oeaw.ac.at/schema#Collection');
            $p = $c->properties['https://vocabs.acdh.oeaw.ac.at/schema#hasLicense'];
            $v = $p->getVocabularyValue('Public Domain Mark 1.0', Ontology::VOCABSVALUE_PREFLABEL);
            $this->assertInstanceOf(SkosConceptDesc::class, $v, $k);
            $this->assertNull($p->getVocabularyValue('foo'), $k);

            $p = $c->properties['https://vocabs.acdh.oeaw.ac.at/schema#hasTitle'];
            $this->assertNull($p->getVocabularyValue('foo'), $k);
        }
    }

    public function testPropertyByClassUri(): void {
        foreach ($this->getOntologies() as $k => $o) {
            $p = $o->getProperty('https://vocabs.acdh.oeaw.ac.at/schema#Collection', 'https://vocabs.acdh.oeaw.ac.at/schema#hasContact');
            $this->assertEquals('Contact(s)', $p->label['en'], $k);
        }
    }

    public function testPropertyWithoutClass(): void {
        foreach ($this->getOntologies() as $k => $o) {
            $p = $o->getProperty(null, 'https://vocabs.acdh.oeaw.ac.at/schema#hasContact');
            $this->assertEquals('Contact(s)', $p->label['en'], $k);
        }
    }

    public function testCache(): void {
        $getters = [
            'db'   => fn($t) => Ontology::factoryDb(self::$pdo, self::$schema, self::CACHE_FILE, $t),
            'rest' => fn($t) => Ontology::factoryRest('http://127.0.0.1/api', self::CACHE_FILE, $t),
        ];
        foreach ($getters as $k => $getter) {
            $this->assertFileDoesNotExist(self::CACHE_FILE);

            $t1     = microtime(true);
            $o1     = $getter(10);
            $t1     = microtime(true) - $t1;
            $this->assertFileExists(self::CACHE_FILE, $k);
            clearstatcache();
            $mtime1 = filemtime(self::CACHE_FILE);

            $t2 = microtime(true);
            $o2 = $getter(10);
            $t2 = microtime(true) - $t2;
            $this->assertEquals($mtime1, filemtime(self::CACHE_FILE), $k);

            sleep(2);

            $t3 = microtime(true);
            $o3 = $getter(1);
            $t3 = microtime(true) - $t3;
            clearstatcache();
            $this->assertGreaterThan($mtime1, filemtime(self::CACHE_FILE), $k);

            $c    = 'https://vocabs.acdh.oeaw.ac.at/schema#Collection';
            $p    = 'https://vocabs.acdh.oeaw.ac.at/schema#hasLicense';
            $p1   = $o1->getProperty($c, $p);
            $p2   = $o2->getProperty($c, $p);
            $p3   = $o3->getProperty($c, $p);
            $attr = [
                'id', 'uri', 'label', 'comment', // BaseDesc
                'property', 'type', 'domain', 'properties', 'range', 'min', 'max',
                'recommendedClass',
                'automatedFill', 'defaultValue', 'langTag', 'ordering', 'vocabs'
            ];
            foreach ($attr as $i) {
                $this->assertEqualsCanonicalizing($p1->$i, $p2->$i, $k);
                $this->assertEqualsCanonicalizing($p1->$i, $p3->$i, $k);
            }

            $v1 = $p1->checkVocabularyValue('Public Domain Mark 1.0', Ontology::VOCABSVALUE_PREFLABEL);
            $this->assertEquals($v1, $p2->checkVocabularyValue('Public Domain Mark 1.0', Ontology::VOCABSVALUE_PREFLABEL), $k);
            $this->assertEquals($v1, $p3->checkVocabularyValue('Public Domain Mark 1.0', Ontology::VOCABSVALUE_PREFLABEL), $k);

            unlink(self::CACHE_FILE);
        }
    }

    public function testGetClasses(): void {
        foreach ($this->getOntologies() as $k => $o) {
            $c = $o->getClasses();
            $this->assertGreaterThan(0, count($c), $k);
            $this->assertInstanceOf(ClassDesc::class, $c[0], $k);
        }
    }

    public function testGetProperties(): void {
        foreach ($this->getOntologies() as $k => $o) {
            $p = $o->getProperties();
            $this->assertGreaterThan(0, count($p), $k);
            $this->assertInstanceOf(PropertyDesc::class, $p[0], $k);
        }
    }

    public function testVocabularyCache(): void {
        foreach ($this->getOntologies() as $k => $o) {
            $c = $o->getClass('https://vocabs.acdh.oeaw.ac.at/schema#Collection');
            $p = $c->properties['https://vocabs.acdh.oeaw.ac.at/schema#hasLicense'];

            $t1 = microtime(true);
            $r1 = $p->checkVocabularyValue('https://vocabs.acdh.oeaw.ac.at/archelicenses/publicdomain-1-0');
            $t2 = microtime(true);
            $r2 = $p->checkVocabularyValue('https://vocabs.acdh.oeaw.ac.at/archelicenses/publicdomain-1-0');
            $t3 = microtime(true);
            $this->assertEquals($r2, $r1);
            $this->assertLessThan($t2 - $t1, 10 * ($t3 - $t2));

            $t1 = microtime(true);
            $r1 = $p->getVocabularyValue('https://vocabs.acdh.oeaw.ac.at/archelicenses/publicdomain-1-0');
            $t2 = microtime(true);
            $r2 = $p->getVocabularyValue('https://vocabs.acdh.oeaw.ac.at/archelicenses/publicdomain-1-0');
            $t3 = microtime(true);
            $this->assertEquals($r2, $r1);
            $this->assertLessThan($t2 - $t1, 10 * ($t3 - $t2));

            $t1 = microtime(true);
            $r1 = $p->getVocabularyValues('https://vocabs.acdh.oeaw.ac.at/archelicenses/publicdomain-1-0');
            $t2 = microtime(true);
            $r2 = $p->getVocabularyValues('https://vocabs.acdh.oeaw.ac.at/archelicenses/publicdomain-1-0');
            $t3 = microtime(true);
            $this->assertEquals($r2, $r1);
            $this->assertLessThan($t2 - $t1, 10 * ($t3 - $t2));
        }
    }
}
