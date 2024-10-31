<?php

/*
 *  (c) , 2011 Wott (http://wott.info/ , wotttt@gmail.com) 
 */

class WPSEO_Sitemaps_SEOTranslate extends WPSEO_Sitemaps {
	var $langs=array();
	
	function redirect() {
		parent::redirect();
	}

	function sitemap_url( $url ) {
		$home = get_bloginfo('url');
		
		if (!$this->langs) {
			global $SEOTranslate_plugin_instance;
			$t = $SEOTranslate_plugin_instance;
			
			foreach ($t->options['seotranslate_lang'] as $code=>$state) {
				$this->langs[]='/'.strtolower($t->options['seotranslate_enlang_list'][$code]);
			}
		}
		
		$output = parent::sitemap_url($url);
		$new_url = $url;
		unset($new_url['images']); // don't repeat images
		foreach ($this->langs as $lang) {
			$new_url['loc'] = substr_replace($url['loc'],$home.$lang,0,strlen($home));
			$output .= parent::sitemap_url($new_url);
		}
		return $output;
	}
	
}
?>
