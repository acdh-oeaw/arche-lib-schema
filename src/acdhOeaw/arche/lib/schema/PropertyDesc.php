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

use zozlak\RdfConstants as RDF;

/**
 * A container for an RDF property description
 *
 * @author zozlak
 */
class PropertyDesc extends BaseDesc {

    /**
     * Property URIs
     * 
     * @var array<string>
     */
    public array $property = [];

    /**
     * Property type URI (owl:DatatypeProperty or owl:ObjectProperty)
     */
    public string $type;

    /**
     * Property domain URI
     * 
     * @var array<string>
     */
    public array $domain = [];

    /**
     * Property URIs of all properties this one inhertis from (includint itself)
     * 
     * @var array<string>
     */
    public array $properties = [];

    /**
     * Property range URI
     * 
     * @var array<string>
     */
    public array $range = [];

    /**
     * Minimum count
     */
    public ?int $min = null;

    /**
     * Maximum count
     */
    public ?int $max = null;

    /**
     * If a class is among acdh:recommendedClass for this property.
     * 
     * After initialization the value is always a boolean one.
     * 
     * @var bool | array<string>
     */
    public bool | array $recommendedClass = [];

    /**
     * achd:automatedFill annotation property value
     */
    public bool $automatedFill = false;

    /**
     * acdh:defaultValue annotation property value
     */
    public ?string $defaultValue = null;

    /**
     * is property range http://www.w3.org/1999/02/22-rdf-syntax-ns#langString
     */
    public bool $langTag;

    /**
     * achd:ordering annotation property value
     */
    public int $ordering = 99999;

    /**
     * acdh:vocabs annotation property value
     */
    public string $vocabs = '';

    /**
     * 
     * acdh:exampleValue annotation property values
     * @var array<string, string>
     */
    public array $exampleValue = [];

    /**
     * Array of vocabulary values fetched from vocabulary pointed by acdh:vocabs
     * annotation property
     * 
     * It's initialized automatically upon the first read.
     * 
     * @var array<SkosConceptDesc>
     */
    private array $vocabularyValues;
    private Ontology $ontologyObj;

    /**
     * 
     * @param object $d
     * @param array<string> $ids
     * @param ?string $nmsp
     * @param ?string $skipNmsp
     */
    public function __construct(?object $d = null, array $ids = [],
                                ?string $nmsp = null, ?string $skipNmsp = null) {
        parent::__construct($d, $ids, $nmsp, $skipNmsp);

        $this->langTag = in_array(RDF::RDF_LANG_STRING, $this->range);
    }

    public function setOntology(Ontology $ontology): void {
        $this->ontologyObj = $ontology;
    }

    public function __sleep(): array {
        // skip vocabularyValues and ontologyObj
        return [
            'id', 'uri', 'label', 'comment', // BaseDesc
            'property', 'type', 'domain', 'properties', 'range', 'min', 'max', 'recommendedClass', // self
            'automatedFill', 'defaultValue', 'langTag', 'ordering', 'vocabs', 'exampleValue' // self
        ];
    }

    public function __get(string $name): mixed {
        if ($name === 'vocabularyValues' && !isset($this->vocabularyValues) && !empty($this->vocabs)) {
            $this->vocabularyValues = $this->ontologyObj->getVocabularyValues($this->vocabs);
        }
        return $this->$name ?? null;
    }

    /**
     * Used to restore the object after unserializing it from cache.
     */
    public function setOntologyObject(Ontology $ontology): void {
        $this->ontologyObj = $ontology;
    }

    /**
     * Returns a list of vocabulary values sorted according to the label
     * property value in a given language.
     * 
     * @param string $lang
     * @return array<SkosConceptDesc>
     */
    public function getVocabularyValues(string $lang = 'en'): array {
        $included = [];
        $ret      = [];
        if (!isset($this->vocabularyValues) && !empty($this->vocabs)) {
            $this->vocabularyValues = $this->ontologyObj->getVocabularyValues($this->vocabs);
        }
        foreach ($this->vocabularyValues ?? [] as $v) {
            $hash = spl_object_hash($v);
            if (!isset($included[$hash])) {
                $ret[$v->getLabel($lang)] = $v;
                $included[$hash]          = true;
            }
        }
        ksort($ret);
        return array_values($ret);
    }

    /**
     * Fetches SkosConceptDesc object desribing a vocabulary value.
     * 
     * @param string $value
     * @param int $searchIn combination of Ontology::VOCABSVALUE_* flags indicating
     *   where to search for the $value (in a concept URI/ID, skos:notation, 
     *   skos:prefLabel, etc.)
     * @return ?SkosConceptDesc
     */
    public function getVocabularyValue(string $value,
                                       int $searchIn = Ontology::VOCABSVALUE_ID): ?SkosConceptDesc {
        if (empty($this->vocabs)) {
            return null;
        }
        return $this->ontologyObj->getVocabularyValue($this->vocabs, $value, $searchIn);
    }

    /**
     * Checks if a given value exists in a given vocabulary.
     * 
     * @param string $value
     * @param int $searchIn combination of Ontology::VOCABSVALUE_* flags indicating
     *   where to search for the $value (in a concept URI/ID, skos:notation, 
     *   skos:prefLabel, etc.)
     * @return string|bool a vocabulary value identifier, `false` if $value is invalid
     *   or `true` when property doesn't use a controlled vocabulary
     */
    public function checkVocabularyValue(string $value,
                                         int $searchIn = Ontology::VOCABSVALUE_ID): string | bool {
        if (empty($this->vocabs)) {
            return true;
        }
        return $this->ontologyObj->checkVocabularyValue($this->vocabs, $value, $searchIn);
    }
}
