<?php

/*
 *  (c) , 2011 Wott (http://wott.info/ , wotttt@gmail.com) 
 */
class MS_Translate_API {

	var $appId = '';
	var $api_base = 'http://api.microsofttranslator.com/v2/Ajax.svc/';

	var $table = 'seotr_caching';

	var $default;
	var $exclude;

	var $debug;
	
	function MS_Translate_API($locale, $debug = false, $exclude = '', $app_id = '') {
		$this->default = $locale;
		$this->exclude = $exclude;
		
		if ($app_id) $this->appId = $app_id;
		
		$this->debug = ($debug)? $debug : new EasyDebugDummy();
	}

	function init() {
		global $wpdb;
		$result = $wpdb->get_col("SHOW TABLES LIKE '{$this->table}'");
		//$wpdb->query("TRUNCATE `{$this->table}`");
		if ($result) return;
              //  $wpdb->query("SET GLOBAl character_set_server = utf8");
                $db = DB_NAME;
                
        // NOTE: attempt to set the charset of this table explicitly -- not the entire server
        // see : http://dev.mysql.com/doc/refman/5.0/en/charset-table.html
		$wpdb->query("CREATE TABLE `{$this->table}` (
			`from` VARCHAR( 15 ) NOT NULL,
			`to` VARCHAR( 15 ) NOT NULL,
			`hash` VARCHAR( 255 ) NOT NULL ,
			`phrase` TEXT NOT NULL ,
			`result` TEXT NOT NULL 
			)CHARACTER SET utf8 COLLATE utf8_general_ci");
		$wpdb->query("ALTER TABLE {$this->table} ADD UNIQUE `index` ( `from` , `to` , `hash` ( 255 ) )");
	}

	function destroy() {
		global $wpdb;
		$wpdb->query("DROP TABLE {$this->table}");
	}
	
	function truncate() {
		global $wpdb;
		$wpdb->query("TRUNCATE TABLE {$this->table}");
	}

	function languages($locale) {
		$lang_list  = array();
		$lang_names = array();
		
		$lang_list = $this->api_request(array(
			'method'=>'GetLanguagesForTranslate'
			));
		
		if ($this->exclude) {
// 			var_dump($lang_list ); echo 'test';exit;
			$lang_list = array_diff($lang_list,  explode(',', $this->exclude));
			sort($lang_list);
			
		}
		$lang_names = $this->api_request(array(
			'method'=>'GetLanguageNames',
			'locale'=>$locale,
			'languageCodes'=>$lang_list
		));
		
		return array_combine($lang_list, $lang_names);
	}
	
	function native_languages() {
		$lang_list = $this->api_request(array(
			'method'=>'GetLanguagesForTranslate'
			));
		if ($this->exclude) {
			$lang_list = array_diff($lang_list,  explode(',', $this->exclude));
			sort($lang_list);
		}
		$lang_names = array();
		foreach($lang_list as $lang) {
			$names = $this->api_request(array(
				'method'=>'GetLanguageNames',
				'locale'=>$lang,
				'languageCodes'=>array($lang)
				));
			if ($names) $lang_names[$lang] = $names[0];
		}
		return $lang_names;
	}

	function translate($text, $to) {
			
		$options = array(
			'method'=>'Translate',
			'from'=>$this->default,
			'to'=>$to,
			'text'=>$text

		);

		if (is_array($text)) {
			$options['method']='TranslateArray';
			$options['texts']=$text;
			unset($options['text']);
		}

		$result = $this->api_request($options);

		// make proper result for both types
		if (is_array($text) && is_array($result)) {
			$res=array();
			foreach($result as $answer) $res[]=$answer->TranslatedText;
			$result=$res;
		} // else error or string is translated from api_requesr perfect

		return $result;
	}

	function caching($texts,$to) {
                
		global $wpdb;
		$this->debug->start('translate');
		$this->debug->add('receive phrases',count($texts));

		$hash = array();
		foreach($texts as $text) $hash[]="'".md5($text)."'";

		$sql = "SELECT * FROM {$this->table} WHERE `from`='{$this->default}' AND `to`='$to' AND `hash` IN (".implode(',',$hash).")";
		//$this->debug_log[]='query sql: '.$sql;
		$cached = $wpdb->get_results($sql);

		$this->debug->point('request db');
		$this->debug->add('found in db',count($cached));

		$result=array();
		$keys = array_flip($texts); // original positions

		foreach($cached as $item) {
			$result[$keys[stripslashes($item->phrase)]] = stripslashes($item->result);
			unset($keys[$item->phrase]);
		}

		if ($keys) {
			$this->debug->add('missed phrases ',count($keys));
			// make new array for translation
                        
			$texts = array_keys($keys);
			$additional = $this->translate($texts, $to);

			$this->debug->point('request API');

			$sql = "INSERT INTO {$this->table} (`from`,`to`,`hash`,`phrase`,`result`) VALUES ";
			foreach($texts as $pos=>$phrase) {
				$result[$keys[$phrase]]=$additional[$pos];
				$sql .= "('{$this->default}','$to','".md5($phrase)."','".addslashes($phrase)."','".addslashes($additional[$pos])."'),";
			}
			//$wpdb->insert($this->table, array('from'=>$this->default,'to'=>$to,'hash'=>md5($phrase),'phrase'=>$phrase,'result'=>$additional[$pos]));
			$sql = rtrim($sql, ','); // remove triling comma
			//$this->debug_log[]='query sql: '.$sql;
			$wpdb->query($sql);
			$this->debug->point('update db');
		}

		$this->debug->add('return strings',count($result));
		$this->debug->stop();
		return $result;
	}



	function make_url($data) {
		$url = $this->api_base.$data['method'].'?appId='.$this->appId;
		unset($data['method']);

		$pars=array();
		foreach($data as $name=>$par) {
			if (is_array($par) || is_object($par))
				$pars[]=$name.'='.urlencode(json_encode($par));
			else
				$pars[]=$name.'='.urlencode($par);
		}
		if ($pars) $url .= '&'.implode('&',$pars);

		// debug info
		$this->debug->add('request url', $url); 
		$this->debug->add('parameters',json_encode($data)); 

		return $url;
	}

	function api_request($data) {
                
		if (!isset($data['method']))
			return new WP_Error('Wrong usage of API', 'method not defined');

		global $wp_version;
		$options = array(
			'timeout' => 30 ,
			'user-agent' => 'WordPress/' . $wp_version . ' SEOTranslate/' . SEOTRANSLATE_VERSION.' '.get_bloginfo( 'url' ),
			'sslverify' => false // prevent some problems with Google in token request
		);

		$response = wp_remote_get($this->make_url($data), $options);
		
		if ( is_wp_error( $response ) ){
			return $response;			
		}
		
		if ( 200 != $response['response']['code'] )
			return new WP_Error('http_request_failed', 'Response code is '.$response['response']['code']);
		
		$result = $response['body'];
		
		
		if(substr($result, 0,3) == pack("CCC",0xef,0xbb,0xbf)) {
			
			$result=substr($result, 3);
		}
		
		$this->debug->add('encoded_result', $result );
		// preg sensitive for \n\n, but we not need any formating inside
		$result = json_decode( str_replace("\n",'',trim( $result,' ?' )) );
				
		if ($result===null){
			return new WP_Error("API result can't be decoded", $response['body']);		
		}
		else return $result;
		
	}


}
?>
