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

/**
 * Description of BaseDesc
 *
 * @author zozlak
 */
class BaseDesc {

    /**
     * Internal id of a corresponding repository resource
     * 
     * @var int
     */
    public $id;
    
    /**
     * The ontology entity URI within the ontology namespace
     * 
     * @var string
     */
    public $uri;
    
    /**
     * Associative array of label values (langauge as a key)
     * 
     * @var string[]
     */
    public $label = [];

    /**
     * Associative array of rdfs:comment values (langauge as a key)
     * 
     * @var string[]
     */
    public $comment = [];

    /**
     * 
     * @param object $d
     * @param array $ids
     * @param string $nmsp
     */
    public function __construct(object $d = null, array $ids = [], string $nmsp = null) {
        $nmspL = strlen($nmsp);
        foreach ($ids as $i){
            if ($nmspL > 0 && substr($i, 0, $nmspL) === $nmsp) {
                $this->uri = $i;
                break;
            }
        }
        if (empty($this->uri)) {
            $this->uri = $ids[0] ?? null;
        }
        
        if ($d !== null) {
            foreach ($this as $k => $v) {
                $dk = strtolower($k);
                if (isset($d->$dk)) {
                    if (is_array($this->$k) && !is_array($d->$dk)) {
                        $this->$k = json_decode($d->$dk, true);
                    } else {
                        $this->$k = $d->$dk;
                    }
                }
            }
            if (isset($d->annotations) && !empty($d->annotations)) {
                foreach (json_decode($d->annotations) as $a) {
                    $prop = preg_replace('|^.*[#/]|', '', $a->property);
                    if (property_exists($this, $prop)) {
                        if (is_array($this->$prop)) {
                            if (!empty($a->lang)) {
                                $this->$prop[$a->lang] = $a->value;
                            } else {
                                $this->$prop[] = $a->value;
                            }
                        } else {
                            $this->$prop = $a->value;
                        }
                    }
                }
            }
        }
    }

    public function getLabel(string $lang, string $fallbackLang = 'en'): string {
        return $this->getPropInLang('label', $lang, $fallbackLang);
    }

    public function getComment(string $lang, string $fallbackLang = 'en'): string {
        return $this->getPropInLang('comment', $lang, $fallbackLang);
    }

    private function getPropInLang(string $property, string $lang,
                                   string $fallbackLang): string {
        return $this->{$property}[$lang] ?? ($this->{$property}[$fallbackLang] ?? (reset($this->$property) ?? ''));
    }

}
