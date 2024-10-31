<?php

/*
 *  (c) , 2011 Wott (http://wott.info/ , wotttt@gmail.com) 
 */

class GoogleSitemapGeneratorSEOTranslate extends GoogleSitemapGenerator {

    var $langs = array();

    function AddUrl($loc, $lastMod = 0, $changeFreq = "monthly", $priority = 0.5) {
        $home = get_bloginfo('url');

        if (!$this->langs) {
            global $SEOTranslate_plugin_instance;
            $t = $SEOTranslate_plugin_instance;

            $this->langs[] = '';
            foreach ($t->options['seotranslate_lang'] as $code => $state) {
                $this->langs[] = '/' . strtolower($t->options['seotranslate_enlang_list'][$code]);
            }
        }

        foreach ($this->langs as $lang) {
            $newloc = substr_replace($loc, $home . $lang, 0, strlen($home));
            parent::AddUrl($newloc, $lastMod, $changeFreq, $priority);
        }
    }

}
?>
