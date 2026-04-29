# arche-lib-schema

[![Latest Stable Version](https://poser.pugx.org/acdh-oeaw/arche-lib-schema/v/stable)](https://packagist.org/packages/acdh-oeaw/arche-lib-schema)
[![Build Status](https://github.com/acdh-oeaw/arche-lib-schema/actions/workflows/test.yml/badge.svg)](https://github.com/acdh-oeaw/arche-lib-schema/actions/workflows/test.yml)
[![Coverage Status](https://coveralls.io/repos/github/acdh-oeaw/arche-lib-schema/badge.svg?branch=master)](https://coveralls.io/github/acdh-oeaw/arche-lib-schema?branch=master)
[![License](https://poser.pugx.org/acdh-oeaw/arche-lib-schema/license)](https://packagist.org/packages/acdh-oeaw/arche-lib-schema)


An API for the ACDH ontology stored in an ARCHE repository.

## Installation

`composer require acdh-oeaw/arche-lib-schema`

## API Documentation

https://acdh-oeaw.github.io/arche-docs/devdocs/namespaces/acdhoeaw-arche-lib-schema.html

## Usage

```php
// if we can set up a direct database connection - this will provide faster 
// initialization and vocabulary value checks
$conn = new PDO('pgsql: repo db connection details');
$cfg = (object) [
    'ontologyNamespace' => 'https://vocabs.acdh.oeaw.ac.at/schema#',
    'parent'            => 'https://vocabs.acdh.oeaw.ac.at/schema#isPartOf',
    'label'             => 'https://vocabs.acdh.oeaw.ac.at/schema#hasTitle',
];
$ontology = \acdhOeaw\arche\lib\schema\Ontology::factoryDb($conn, $cfg);
// or just from the ARCHE API URL - slower but always works
$ontology = \acdhOeaw\arche\lib\schema\Ontology::factoryRest('https://arche.acdh.oeaw.ac.at/api');

$class = $ontology->getClass('https://vocabs.acdh.oeaw.ac.at/schema#Person');
print_r($class);

$property = $ontology->getProperty('https://vocabs.acdh.oeaw.ac.at/schema#RepoObject', 'https://vocabs.acdh.oeaw.ac.at/schema#hasContact');
print_r($property);

$property = $ontology->getProperty(null, 'https://vocabs.acdh.oeaw.ac.at/schema#hasContact');
print_r($property);

// controlled vocabulary on a property
$property = $ontology->getProperty(null, 'https://vocabs.acdh.oeaw.ac.at/schema#hasLicense');
echo $property->checkVocabularyValue('cc-by-4-0', \acdhOeaw\arche\lib\schema\Ontology::VOCABSVALUE_ALL); // doesn't fetch  all vocabulary values
print_r($property->getVocabularyValue('https://vocabs.acdh.oeaw.ac.at/archelicenses/cc-by-4-0')); // doesn't fetch  all vocabulary values
print_r($property->vocabularyValues); // fetches all values
echo $property->vocabularyValues['https://vocabs.acdh.oeaw.ac.at/archelicenses/cc-by-4-0']->getLabel('de'); // fetches all values first if they aren't loaded yet

// store cache in ontology.cache and refresh it every 600s
$ontology = new \acdhOeaw\arche\lib\schema\Ontology::factoryDb($conn, $cfg, 'ontology.cache', 600);
$ontology = new \acdhOeaw\arche\lib\schema\Ontology::factoryRest('https://arche.acdh.oeaw.ac.at', 'ontology.cache', 600);
```
