<?php
/*
  Plugin Name: SEO Translate
  Description: Automatically translate your content into 30 different languages using the Microsoft Translator. Super easy install and use. Drive traffic - all translated content is stored on your servers and sitemap, ready to be indexed by search engines.
  Version: 2.3
  Author: FoxTranslate
  Author URI: http://www.foxtranslate.com/
  Plugin URI: http://seotranslate.com
  Text Domain: seotranslate
  Domain Path: /lang/
  Copyright 2011 Wott ( email : wotttt@gmail.com )

 */

define('SEOTRANSLATE_VERSION', '2.3');


if (!class_exists("SEOTranslate")) {

    class SEOTranslate {

        var $options = array(
            'seotranslate_default' => 'en',
            'seotranslate_lang_list' => true,
            'seotranslate_lang_native' => false,
            'seotranslate_enlang_list' => false,
            'seotranslate_lang' => array(),
            'seotranslate_switcher' => array(),
            'seotranslate_exclude' => 'fi,ht,lt,lv,et,sl,ca,mww',
            'seotranslate_ms_app_id' => '',
            'seotranslate_debug' => false
        );
        var $plugin_URL;
        var $translator;
        var $cookie_status;
        var $debug = false;
        // workflow variables
        var $translated = false;
        var $google_sitemap_generator = false;
        var $wordpress_seo = false;
        var $sitemap_is_final = false;

        function SEOTranslate() {
            global $wpdb;

            // check the cookies status
            $this->cookie_status = $this->check_cookie();

            // set global utf8
            //$wpdb->query("SET GLOBAL character_set_server = utf8");
            // register activation and deactivation
            register_activation_hook(__FILE__, array(&$this, 'init_options'));
            register_deactivation_hook(__FILE__, array(&$this, 'kill_options'));
            if (get_option("intall") == "1") {


                $this->plugin_URL = WP_PLUGIN_URL . '/' . dirname(plugin_basename(__FILE__));

                foreach ($this->options as $key => $option) {
                    $this->options[$key] = get_option($key, $option);
                }

                if (is_admin()) {
                    add_filter('plugin_action_links_' . plugin_basename(__FILE__), array(&$this, 'add_settings_link'));
                    add_action('admin_menu', array(&$this, 'add_settings_page'));
                }
//                var_dump($this->options['seotranslate_lang_list']);exit;
                if ($this->options['seotranslate_lang_list']) {

                    // include the plugin classes
                    require_once(dirname(__FILE__) . '/includes/class.easydebug.php');
                    require_once(dirname(__FILE__) . '/includes/class.ms_translate_api.php');
                    require_once(dirname(__FILE__) . '/includes/class.seotranslatewidget.php');

                    // init debugger
                    $this->debug = ($this->options['seotranslate_debug']) ? new EasyDebug() : new EasyDebugDummy();


                    $this->translator = new MS_Translate_API($this->options['seotranslate_default'], $this->debug, $this->options['seotranslate_exclude'], $this->options['seotranslate_ms_app_id']);

                    add_action('init', array(&$this, 'load_textdomain'));

                    if (is_admin()) {
//                        add_filter('plugin_action_links_' . plugin_basename(__FILE__), array(&$this, 'add_settings_link'));
//                        add_action('admin_menu', array(&$this, 'add_settings_page'));
                    } else {
                        add_action('template_redirect', array(&$this, 'template_redirect'), 0);
                        add_action('wp_head', array(&$this, 'add_styles'), 7);
                        add_action('init', array(&$this, 'check_language'), 0);
                        add_filter('redirect_canonical', array(&$this, 'redirect_canonical'), 10, 2);
                        add_filter('the_content', array(&$this, 'the_content'), 9999);
                        add_action('wp_footer', array(&$this, 'add_footer_link'));
                    }

                    add_action('wp_insert_comment', array(&$this, 'wp_insert_comment'), 10, 2);
                    add_action('widgets_init', array(&$this, 'widgets_init'));
                    $plugins = get_option('active_plugins');
                    foreach ($plugins as $p) {
                        if (strpos($p, 'google-sitemap-generator') === 0) {
                            $this->google_sitemap_generator = preg_replace('!/.*$!', '/sitemap-core.php', $p);
                        }
                        if (strpos($p, 'wordpress-seo') === 0) {
                            $this->wordpress_seo = true;
                        }
                    }


                    // Google XML sitemap plugin integration
                    if ($this->google_sitemap_generator) {
                        add_action('sm_build_cron', array(&$this, 'gsmg_override_addurl'), 0);
                        add_action('delete_post', array(&$this, 'gsmg_override_addurl'), 0);
                        add_action('publish_post', array(&$this, 'gsmg_override_addurl'), 0);
                        add_action('publish_page', array(&$this, 'gsmg_override_addurl'), 0);
                        add_action('sm_rebuild', array(&$this, 'gsmg_override_addurl'), 0);
                        add_action('load-settings_page_google-sitemap-generator/sitemap', array(&$this, 'gsmg_override_addurl'), 0);
                    }

                    if ($this->wordpress_seo) {
                        add_action('plugins_loaded', array(&$this, 'wpseo_override_sitemap_url'), 0);
                    }
                }

                $this->googleSitemapIsFinal();
            }
        }

        function save_error() {
            update_option('plugin_error', ob_get_contents());
        }

        function googleSitemapIsFinal() {
            if (!$this->google_sitemap_generator) {
                return;
            }
            if (!class_exists("GoogleSitemapGenerator")) {
                if (file_exists(WP_PLUGIN_DIR . '/' . $this->google_sitemap_generator)) {
                    require_once(WP_PLUGIN_DIR . '/' . $this->google_sitemap_generator);
                    $reflector = new ReflectionClass('GoogleSitemapGenerator');
                    if ($reflector->isFinal()) {
                        $this->sitemap_is_final = true;
                    }
                }
            } else {
                $reflector = new ReflectionClass('GoogleSitemapGenerator');
                if ($reflector->isFinal()) {
                    $this->sitemap_is_final = true;
                }
            }
        }

        //checking cookie status
        function check_cookie() {
            if (setcookie("test", "test", time() + 360)) {
                if (isset($_COOKIE['test'])) {
                    unset($_COOKIE['test']);
                    return true;
                }
            }
            return false;
        }

        // the form for sites have to be 1-column-layout
        function init_options() {
            $locale = get_option('seotranslate_default', $this->options['seotranslate_default']);
            $exclude = get_option('seotranslate_exclude', $this->options['seotranslate_exclude']);
            $ms_app_id = get_option('seotranslate_ms_app_id', $this->options['seotranslate_ms_app_id']);
            $intalls = get_option('intall');
            require_once(dirname(__FILE__) . '/includes/class.easydebug.php');
            require_once(dirname(__FILE__) . '/includes/class.ms_translate_api.php');



            if (!$intalls || $this->isValidId() || !$this->options['seotranslate_lang_list']) {
                $langArray = Array(
                    'ar' => 'Arabic',
                    'bg' => 'Bulgarian',
                    'cs' => 'Czech',
                    'da' => 'Danish',
                    'de' => 'German',
                    'el' => 'Greek',
                    'en' => 'English',
                    'es' => 'Spanish',
                    'fr' => 'French',
                    'he' => 'Hebrew',
                    'hi' => 'Hindi',
                    'hu' => 'Hungarian',
                    'id' => 'Indonesian',
                    'it' => 'Italian',
                    'ja' => 'Japanese',
                    'ko' => 'Korean',
                    'nl' => 'Dutch',
                    'no' => 'Norwegian',
                    'pl' => 'Polish',
                    'pt' => 'Portuguese',
                    'ro' => 'Romanian',
                    'ru' => 'Russian',
                    'sk' => 'Slovak',
                    'sv' => 'Swedish',
                    'th' => 'Thai',
                    'tr' => 'Turkish',
                    'uk' => 'Ukrainian',
                    'vi' => 'Vietnamese',
                    'zh-CHS' => 'Chinese Simplified',
                    'zh-CHT' => 'Chinese Traditional',
                );

                $this->options['seotranslate_lang_list'] = $langArray;
                update_option('seotranslate_lang_list', $langArray);
                $this->options['seotranslate_lang_native'] = $langArray;
                update_option('seotranslate_lang_native', $langArray);
                update_option('seotranslate_enlang_list', $langArray);

                $this->translator = new MS_Translate_API($locale, false, $exclude, $ms_app_id);
                $this->translator->init();
                $intalls = get_option('intall');

                if (!$intalls) {
                    add_option('intall', '1');
                }
            }
        }

        function kill_options() {
            delete_option('seotranslate_lang_list');
            delete_option('intall');
            //update_option('seotranslate_ms_app_id', '');
            require_once(dirname(__FILE__) . '/includes/class.easydebug.php');
            require_once(dirname(__FILE__) . '/includes/class.ms_translate_api.php');

            $this->translator = new MS_Translate_API('en');

            $this->translator->destroy();
        }

        function load_textdomain() {
            if (function_exists('load_plugin_textdomain')) {
                load_plugin_textdomain('seotranslate', false, dirname(plugin_basename(__FILE__)) . '/lang');
            }
        }

        function template_redirect() {
            if ($this->isValidId()) {
                ob_start(array(&$this, 'ob_end'));
                $this->debug->start();
            }
        }

        function trim($str) {
            return trim(str_replace("&nbsp;", ' ', $str));
        }

        function ob_end($content) {

            $this->debug->point('page built');

            $content = preg_replace('/<body[^>]*>/', "$0" . $this->top_block(), $content, 1);

            if (!$this->translated)
                return $content;

            /*
              if (preg_match_all('%<script\D[\s\S]*?>*?</script>%', $content, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
              $scripts = $m;
              $content = preg_replace('%<script[\s\S]*?>*?</script>%', '', $content);
              } */
            $original_content = $content;

            // exclude comment blocks - find id or class with 'comment-#id' and save the block until pieces prepare by replacing block by same amount of spaces


            if (preg_match_all("!<(\w+) [^>]*?class=['\"]([^>]*?comment-body[^>]*?)['\"][^>]*?>!i", $content, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                $this->debug->add('comments', count($m));
                foreach ($m as $n => $r) {
                    //$this->debug->start('comment #'.($n+1));
                    $start = $r[0][1]; // start and end wiped block including div tags
                    $level = 0;
                    $len = 0; // closing tag missing
                    //$this->debug->add('start',$start);
                    // search end of tag ignore same tags pair from founded position
                    if (preg_match_all("!<(/?)({$r[1][0]})( [^>]*?)?>!i", $content, $m2, PREG_SET_ORDER | PREG_OFFSET_CAPTURE, $start)) {
                        foreach ($m2 as $r2) {
                            $level += ( $r2[1][0] == '/') ? -1 : 1;
                            if ($level === 0) {
                                $len = $r2[0][1] + strlen($r2[0][0]) - $start;
                                break;
                            }
                        }
                    }

                    //$this->debug->add('lenght',$len);
                    // replace block by spaces
                    //$this->debug->add('content',substr($content,$start,$len));
                    $content = substr_replace($content, str_repeat(' ', $len), $start, $len);

                    //$this->debug->stop();
                }
            }

            // admin bar
            if (preg_match_all("!<(\w+) [^>]*?id=['\"](wpadminbar)['\"][^>]*?>!i", $content, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                $this->debug->add('admin bar', count($m));
                foreach ($m as $n => $r) {
                    //$this->debug->start('admin bar #'.($n+1));
                    $start = $r[0][1]; // start and end wiped block including div tags
                    $level = 0;
                    $len = 0; // closing tag missing
                    // search end of tag ignore same tags pair from founded position
                    if (preg_match_all("!<(/?)({$r[1][0]})( .*?)?>!i", $content, $m2, PREG_SET_ORDER | PREG_OFFSET_CAPTURE, $start)) {
                        foreach ($m2 as $r2) {
                            $level += ( $r2[1][0] == '/') ? -1 : 1;
                            if ($level === 0) {
                                $len = $r2[0][1] + strlen($r2[0][0]) - $start;
                                break;
                            }
                        }
                    }

                    // replace block by spaces
                    //$this->debug->add('lenght',$len);
                    //$this->debug->add('content',substr($content,$start,$len));
                    $content = substr_replace($content, str_repeat(' ', $len), $start, $len);

                    //$this->debug->stop();
                }
            }

            // flags and widget
            if (preg_match_all("!<(\w+) [^>]*?class=['\"]([^'\"]*?seotranslate-notranslate[^'\"]*)['\"][^>]*?>!i", $content, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                $this->debug->add('notranslates', count($m));
                foreach ($m as $n => $r) {
                    $this->debug->start('notranslate #' . ($n + 1));
                    $start = $r[0][1]; // start and end wiped block including div tags
                    $level = 0;
                    $len = 0; // closing tag missing
                    // search end of tag ignore same tags pair from founded position
                    if (preg_match_all("!<(/?)({$r[1][0]})( .*?)?>!i", $content, $m2, PREG_SET_ORDER | PREG_OFFSET_CAPTURE, $start)) {
                        foreach ($m2 as $r2) {
                            $level += ( $r2[1][0] == '/') ? -1 : 1;
                            if ($level === 0) {
                                $len = $r2[0][1] + strlen($r2[0][0]) - $start;
                                break;
                            }
                        }
                    }



                    // replace block by spaces
                    $this->debug->add('lenght', $len);
                    $this->debug->add('content', substr($content, $start, $len));
                    $content = substr_replace($content, str_repeat(' ', $len), $start, $len);

                    $this->debug->stop();
                }
            }


            $pieces = array();
            /* title found by later regexp
              if (preg_match('!<title>(.*?)</title>!i', $content, $m, PREG_OFFSET_CAPTURE))
              if ($this->trim($m[1][0])) $pieces[$m[1][0]]=array($m[1][1]);
             */

            if (preg_match_all("!<meta [^>]*?name=['\"](.+?)['\"].+?content=['\"](.*?)['\"].*?/?>!i", $content, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                foreach ($m as $r) {
                    $find = strtolower($r[1][0]);
                    $text = $r[2][0];
                    if (($find == 'description' || $find == 'keywords') && $this->trim($text))
                        $pieces[$text] = (isset($pieces[$text])) ? array_merge($pieces[$text], array($r[2][1])) : array($r[2][1]);
                }
            }

            if (preg_match_all("!<img [^>]*?alt=['\"](.*?)['\"][^>]*>!i", $content, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                foreach ($m as $r) {
                    $text = $r[1][0];
                    if ($this->trim($text))
                        $pieces[$text] = (isset($pieces[$text])) ? array_merge($pieces[$text], array($r[1][1])) : array($r[1][1]);
                }
            }

            if (preg_match_all("!<a [^>]*?title=['\"](.*?)['\"][^>]*>!i", $content, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                foreach ($m as $r) {
                    $text = $r[1][0];
                    if ($this->trim($text))
                        $pieces[$text] = (isset($pieces[$text])) ? array_merge($pieces[$text], array($r[1][1])) : array($r[1][1]);
                }
            }

            if (preg_match_all("@[^-=]>([^<]*)<(?!/style|/script|/code|/pre)@i", $content, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                foreach ($m as $r) {
                    $temp = substr($content, 0, $r[0][1]);
                    $pos1 = strlen($temp) - strpos(strrev($temp), strrev('<script')) - strlen('<script');
                    $pos2 = strlen($temp) - strpos(strrev($temp), strrev('</script>')) - strlen('</script>');
                    if ($pos1 > $pos2) {
                        continue;
                    } else {
                        $text = $r[1][0];
                        if ($this->trim($text))
                            $pieces[$text] = (isset($pieces[$text])) ? array_merge($pieces[$text], array($r[1][1])) : array($r[1][1]);
                    }
                }
            }

            // restore replaced
            $content = $original_content;


            $texts = array_keys($pieces);

            $positions = array();
            foreach ($texts as $i => $key) {
                foreach ($pieces[$key] as $p)
                    $positions[$p] = $i;
            }
            krsort($positions);


            $this->debug->point('parse page');

            $new = $this->translator->caching($texts, $this->translated);
            $this->debug->point('translate');

            if (is_wp_error($new)) {
                $content .= "<!-- Translator error: \n";
                foreach ($new->errors as $code => $message)
                    $content .= "code: $code \nmessage: $message\n";
                $content .= "-->";
            } else if (!is_array($new)) {
                $content .= "<!-- Translator error: \n";
                $content .= "code: API error \n";
                $content .= "mesage: API return non-array result \n";
                $content .= "url: {$this->translator->url} \n";
                $content .= "encoded: {$this->translator->encoded_result} \n";
                $content .= "-->";
            } else if (count($new) != count($pieces)) {
                $content .= "<!-- Translator error: \n";
                $content .= "code: API error \n";
                $content .= "message: API return inconsistent result, size of translated array " . count($new) . "!=" . count($pieces) . " size of requested array \n";
                $content .= "url: {$this->translator->url} \n";
                $content .= "encoded: {$this->translator->encoded_result} \n";
                $content .= "-->";
            } else {
                foreach ($positions as $p => $i) {
                    $content = substr_replace($content, $new[$i], $p, strlen($texts[$i]));
                }
                /* $str = '';
                  foreach ($scripts as $s) {
                  $str .= $s[0][0];
                  }

                  $pos = strpos($content, '</head>');
                  $temp = $content;
                  $content = substr($temp, 0, $pos) . $str . substr($temp, $pos, -1);
                 */
            }

            $content .= $this->debug->log("<!-- Translator debug info:", "-->");

            return $content;
        }

        function check_language() {


            $this->prefix = preg_replace('!https?://[^/]+!', '', get_bloginfo('url'));
            $url = str_replace($this->prefix, '', $_SERVER['REQUEST_URI']);
            // exceptions


            $file = preg_replace('!^.*/!', '/', preg_replace('!\?.*$!', '', $url)); //echo $file; // remove path and parameters from request
            // virtual files
            if ($file == '/robots.txt')
                return;
            if (substr($file, -4) == '.xml')
                return; // any xml virtual files 








                
// index and real files
            if ($file != '/' && file_exists(ABSPATH . $file))
                return;
            # find current language
            if (is_array($this->options['seotranslate_lang'])) {
                $langs = array_merge(array("{$this->options['seotranslate_default']}" => 'default'), $this->options['seotranslate_lang']);
                // check permalinks
                if (get_option('permalink_structure') == '') {
                    // default - no permalinks - use get parameter ?lang=lang
                    // check get parameters
                    if (preg_match_all('!\?(?:([-\w+.~%]+)=([-\w+.~%]+)&?)+!', $url, $m, PREG_SET_ORDER)) {
                        $code = 'default';
                        foreach ($m as $r) {
                            if ($r[1] == 'lang') {
                                $code = $r[2];
                                break;
                            }
                        }
                        if ($code != 'default' && isset($langs[$code])) {
                            $this->translated = $code;
                            $_SERVER['REQUEST_URI'] = str_replace("lang=$code", '', $_SERVER['REQUEST_URI']); // remove parameter lang
                            $_SERVER['REQUEST_URI'] = str_replace("&&", '&', $_SERVER['REQUEST_URI']); //  remove separator duplicates
                            $_SERVER['REQUEST_URI'] = rtrim($_SERVER['REQUEST_URI'], '?&'); // remove empthy list
                        }
                    }
                } else {
                    // use permalink prefix for the lang
                    foreach ($langs as $code => $state) {
                        $name = str_replace(' ', '-', strtolower($this->options['seotranslate_enlang_list'][$code]));
                        if (strpos($url, $name) === 1) {
                            if (end(str_split($url)) !== '/') {
                                $this->translated = $code;
                                $_SERVER['REQUEST_URI'] = str_replace("/$name", '/', $_SERVER['REQUEST_URI']);
                                break;
                            } else {
                                $this->translated = $code;
                                $_SERVER['REQUEST_URI'] = str_replace("/$name/", '/', $_SERVER['REQUEST_URI']);
                                break;
                            }
                        }
                    }
                }
            }            // return to default page

            if ($this->translated == $this->options['seotranslate_default']) {
                if ($this->cookie_status) {
                    setcookie('seotranslate_language', ' ', time() - 31536000, COOKIEPATH, COOKIE_DOMAIN);
                } else {
                    delete_transient('seotranslate_language');
                }
                $this->translated = false;
                wp_redirect($_SERVER['REQUEST_URI']);
                die();
            }
            // redirect to translated page by new click
            if (isset($_SERVER['HTTP_REFERER']) && !$this->translated && (get_transient('seotranslate_language') || isset($_COOKIE['seotranslate_language']))) {
                $code = null;
                if ($this->cookie_status) {
                    $code = $_COOKIE['seotranslate_language'];
                } else {
                    $code = get_transient('seotranslate_language');
                }
                if (isset($this->options['seotranslate_enlang_list'][$code]) && isset($this->options['seotranslate_lang'][$code])) {
                    if (get_option('permalink_structure') == '') {
                        if (strpos($_SERVER['HTTP_REFERER'], "lang=$code") !== false) {
                            $this->translated = false;
                            if (strpos($_SERVER['REQUEST_URI'], "?") !== false) {
                                wp_redirect($_SERVER['REQUEST_URI'] . '&' . "lang=$code");
                            } else {
                                wp_redirect($_SERVER['REQUEST_URI'] . '?' . "lang=$code");
                            }
                            die();
                        }
                    } else {
                        // use English name as lang code
                        $code = str_replace(' ', '-', strtolower($this->options['seotranslate_enlang_list'][$code]));
                        if (strpos(str_replace(get_bloginfo('url'), '', $_SERVER['HTTP_REFERER']), $code) === 1) {
                            $this->translated = false;
                            wp_redirect($this->prefix . '/' . $code . $url);
                            die();
                        }
                    }
                }
            }
            // set cookie for redirect
            if ($this->cookie_status) {
                if ($this->translated) {
                    setcookie('seotranslate_language', $this->translated, time() + 31536000, COOKIEPATH, COOKIE_DOMAIN);
                } else {
                    setcookie('seotranslate_language', ' ', time() - 31536000, COOKIEPATH, COOKIE_DOMAIN);
                }
            } else {
                if ($this->translated) {
                    set_transient('seotranslate_language', $this->translated);
                } else {
                    delete_transient('seotranslate_language');
                }
            }
        }

        function redirect_canonical($redirect_url, $requested_url) {

            if ($redirect_url != $requested_url && get_option('permalink_structure')) {
                // convert lang=code parameter to the url path
                if (preg_match_all('!\?(?:([-\w+.~%]+)=([-\w+.~%]+)&?)+!', $redirect_url, $m, PREG_SET_ORDER)) {
                    $code = 'default';
                    foreach ($m as $r) {
                        if ($r[1] == 'lang') {
                            $code = $r[2];
                            break;
                        }
                    }
                    $langs = array_merge(
                            array("{$this->options['seotranslate_default']}" => 'default'), $this->options['seotranslate_lang']);
                    if ($code != 'default' && isset($langs[$code])) {
                        $url = str_replace("lang=$code", '', $redirect_url); // remove parameter lang
                        $url = str_replace("&&", '&', $redirect_url); //  remove separator duplicates
                        $url = rtrim($redirect_url, '?&'); // remove empthy list
                    }
                    $code = str_replace(' ', '-', strtolower($this->options['seotranslate_enlang_list'][$code]));
                    $url = str_replace(get_bloginfo('url'), get_bloginfo('url') . '/' . $code, $redirect_url);

                    if (!redirect_canonical($redirect_url, false) && $url !== $redirect_url) {
                        wp_redirect($url, 301);
                        exit();
                    }
                }
            }
            return $redirect_url;
        }

        function gsmg_override_addurl() {
            if (!$this->google_sitemap_generator)
                return;
            if (!class_exists("GoogleSitemapGenerator")) {
                if (!file_exists(WP_PLUGIN_DIR . '/' . $this->google_sitemap_generator))
                    return false;
                require_once(WP_PLUGIN_DIR . '/' . $this->google_sitemap_generator);
            }
            if (!class_exists("GoogleSitemapGeneratorSEOTranslate")) {
                if (!$this->sitemap_is_final) {
                    require_once(dirname(__FILE__) . '/includes/class.googlesitemapgenerator.php');
                    $GLOBALS["sm_instance"] = new GoogleSitemapGeneratorSEOTranslate();
                }
            }
        }

        function wpseo_override_sitemap_url() {
            if (!$this->wordpress_seo)
                return;
            if (class_exists("WPSEO_Sitemaps") && !class_exists('WPSEO_Sitemaps_SEOTranslate')) {
                require_once(dirname(__FILE__) . '/includes/class.wpseo_sitemaps.php');
                global $wpseo_sitemaps;
                remove_action('template_redirect', array(&$wpseo_sitemaps, 'redirect'));
                $wpseo_sitemaps = new WPSEO_Sitemaps_SEOTranslate();
                add_action('template_redirect', array(&$wpseo_sitemaps, 'redirect'));
            }
        }

        function add_styles() {
            wp_enqueue_style('seotranslate_style', $this->plugin_URL . '/seotranslate.css', array(), SEOTRANSLATE_VERSION);
        }

        function add_footer_link() {
            if (!$this->translated)
                return;
            if ($this->isValidId()) {
                echo "<div class='seotranslate_footer'>
                      	<a href='http://www.foxtranslate.com' class='seotranslate_footer'>.</a>
                      	<a href='http://seotranslate.com' class='seotranslate_footer'>.</a>
                      </div>";
            }
        }

        function top_block() {

            $note = array();

            if ($this->translated)
                $note[] = '<span>' . __("Machine Translation by ", 'seotranslate') . '</span>' . '<span class="seotranslate-notranslate">Microsoft Translator</span>';

            if (isset($this->options['seotranslate_switcher']['above'])) {
                $note[] = $this->switcher('above');
            }

            if ($note)
                return "\n" . '<div id="seotranslate_switcher_above" class="seotranslate_switcher">' . implode('<span>&nbsp;|&nbsp;</span>', $note) . '</div>';
            else
                return '';
        }

        function the_content($content) {
            if (is_feed() || (!is_single() && !is_page())) {
                return $content;
            }

            if (((isset($this->options['seotranslate_switcher']['post']) && is_single()) ||
                    (isset($this->options['seotranslate_switcher']['page']) && is_page())) && $this->isValidId()) {

                $switcher = $this->switcher('post');
                $switcher1 = '<div id="seotranslate_switcher_top" class="seotranslate_switcher">' . $switcher . '</div>';
                /* $switcher2 = '<div id="seotranslate_switcher_bottom" class="seotranslate_switcher">'.$switcher.'</div>';
                  return $switcher1.$content.$switcher2; */
                return $switcher1 . $content;
            } else
                return $content;
        }

        function wp_insert_comment($id, $comment) {
            if ($this->cookie_status) {
                update_comment_meta($id, 'lang', (isset($_COOKIE['seotranslate_language']) ? $_COOKIE['seotranslate_language'] : ''));
            } else {
                update_comment_meta($id, 'lang', get_transient('seotranslate_language') ? get_transient('seotranslate_language') : '');
            }
        }

        function widgets_init() {
            register_widget('SEOTranslateWidget');
        }

        function adopt_lang($el) {
            return preg_replace('/(-|;).*/', '', $el);
        }

        function sort_by_accept($a, $b) {
            $a = (isset($this->accept[$a])) ? $this->accept[$a] : $this->options['seotranslate_lang'][$a] + 1000;
            $b = (isset($this->accept[$b])) ? $this->accept[$b] : $this->options['seotranslate_lang'][$b] + 1000;
            if ($a == $b)
                return 0;
            return ($a < $b) ? -1 : 1;
        }

        function switcher($type, $tag = '', $flags = true, $additions = '') {
            $switcher = ($type == 'above' || $type == 'post') ? '<span>' . __('Languages Available: ', 'seotranslate') . '</span>' : '';

            $before = $after = '';
            if ($tag) {
                $before = "<$tag $additions>";
                $after = "</$tag>";
            }

            // add default language for return to origignal page
            $langs = $this->options['seotranslate_lang'];


            if (isset($_SERVER["HTTP_ACCEPT_LANGUAGE"])) {
                // move accepted lang first
                $this->accept = array_flip(array_map(array(&$this, 'adopt_lang'), explode(',', $_SERVER["HTTP_ACCEPT_LANGUAGE"])));
                uksort($langs, array(&$this, 'sort_by_accept'));
            }

            // default first
            $langs = array_merge(array("{$this->options['seotranslate_default']}" => 'default'), $langs);

            $is_europe = false;
            //$is_europe = array_key_exists ( array('cs', 'da', 'nl', 'fr', 'de', 'el', 'it', 'no', 'sv') , $langs );
            $europeLangs = array('cs', 'da', 'nl', 'fr', 'de', 'el', 'it', 'no', 'sv');

            $is_europe = in_array($this->options['seotranslate_default'], $europeLangs);


            foreach ($langs as $code => $state) {
                $default = false;
                $name = $this->options['seotranslate_lang_list'][$code] . '&nbsp;'; //|&nbsp;' . $this->options['seotranslate_lang_native'][$code];
                // make lang url
                if (get_option('permalink_structure') == '') {
                    if (strpos($_SERVER['REQUEST_URI'], '?') !== false)
                        $url = $_SERVER['REQUEST_URI'] . '&' . "lang=$code";
                    else
                        $url = $_SERVER['REQUEST_URI'] . '?' . "lang=$code";
                } else {

                    if ($code != $this->options['seotranslate_default']) {
                        $url = get_home_url(null, str_replace(' ', '-', strtolower($this->options['seotranslate_enlang_list'][$code]))) .
                                str_replace($this->prefix, '', $_SERVER['REQUEST_URI']);
                    } else {
                        $url = get_home_url(null, str_replace(' ', '-', strtolower($this->options['seotranslate_enlang_list'][$code]))) .
                                str_replace($this->prefix, '', $_SERVER['REQUEST_URI']);
                        $default = true;
                    }

                    /* Fix for default language name appearence in url */

                    /* if ($code != $this->options['seotranslate_default']) {
                      $url = get_home_url(null, str_replace(' ', '-', strtolower($this->options['seotranslate_enlang_list'][$code]))) .
                      str_replace($this->prefix, '', $_SERVER['REQUEST_URI']);
                      } else {
                      $url = get_home_url(null, str_replace($this->prefix, '', $_SERVER['REQUEST_URI']));
                      } */
                }

                $active = array();
                if ($type != 'widget')
                    $active[] = 'seotranslate-notranslate';

                if (($this->translated == $code) ||
                        (!$this->translated && $code == $this->options['seotranslate_default'])) {
                    $active[] = 'active_lang';
                }

                if ($is_europe && $code == 'en') {
                    $code = "en-GB";
                }

                if ($flags) {
                    $active[] = "seotranslate_flag-$code";
                    if ($type == 'widget')
                        $active[] = "seotranslate_flag";
                }
                if ($default == true) {
                    $active[] = 'default_lang';
                }

                if ($active)
                    $active = ' class="' . implode(' ', $active) . '"';
                else
                    $active = '';
                if ($default == true) {
                    $switcher .= "<input type='hidden' class ='seotr_default' value='" . $url . "'>";
                    $switcher .= "<input type='hidden' class ='seotr_default_changed' value='" . get_home_url(null, str_replace($this->prefix, '', $_SERVER['REQUEST_URI'])) . "'>";
                }
                $switcher .= "$before<a href=\"$url\" title=\"$name\"$active>";
                //if ($flags) $switcher .= "<img src=\"{$this->plugin_URL}/flags/$code.png\" alt=\"$name\" />";
                if ($type == 'widget')
                    $switcher .= "<span>$name</span>";
                else
                    $switcher .= '.'; // use to prevent avoid a tag with empty content by browser
                $switcher .= '</a>' . $after;
            }
            $switcher.="
            	<script>window.jQuery || document.write('<script src=\"//ajax.googleapis.com/ajax/libs/jquery/1.7.1/jquery.min.js\"><\/script>')</script>
            	<script type='text/javascript'>
                    window.onload = function() {
                        jQuery('.default_lang').attr('href' , jQuery('.seotr_default_changed').val());
                    };
                    
                    jQuery('.default_lang').click(function(){
                        jQuery(this).attr('href' , jQuery('.seotr_default').val());
                    });
                </script>
            ";
            return $switcher;
        }

        function add_settings_page() {
            if (!current_user_can('manage_options'))
                return;
            add_options_page(__('SEO Translate', 'seotranslate'), __('SEO Translate', 'seotranslate'), 'manage_options', 'seo-translate', array(&$this, 'settings_form'));
            add_action('admin_init', array(&$this, 'settings_reg'));
            add_action('admin_print_styles-settings_page_seo-translate', array(&$this, 'settings_style'));
        }

        function settings_style() {
            wp_enqueue_style('seotranslate_style', $this->plugin_URL . '/seotranslate.css', array(), SEOTRANSLATE_VERSION);
        }

        function settings_reg() {
            register_setting('seo-translate', 'seotranslate_default');
            register_setting('seo-translate', 'seotranslate_lang');
            register_setting('seo-translate', 'seotranslate_switcher');
            register_setting('seo-translate', 'seotranslate_ms_app_id');
            register_setting('seo-translate', 'seotranslate_ms_app_id_old');

            $old = get_option("seotranslate_ms_app_id");
            $new = get_option("seotranslate_ms_app_id_old");

            if ($old != $new) {
                $this->clearCache();
            }
        }

        function add_settings_link($links) {
            if (!current_user_can('manage_options'))
                return $links;
            $settings_link = '<a href="options-general.php?page=seo-translate">' . __('Settings', 'seotranslate') . '</a>';
            array_unshift($links, $settings_link);

            return $links;
        }

        function settings_form() {

            if (isset($_GET['settings-updated']) && 'true' == $_GET['settings-updated']) {
                $validAppId = $this->isValidId();
                if ($validAppId) {
                    $this->options['seotranslate_lang_list'] = $this->translator->languages(get_option('seotranslate_default', $this->options['seotranslate_default']));
                    update_option('seotranslate_lang_list', $this->options['seotranslate_lang_list']);
                }
                $this->options['seotranslate_switcher'] = get_option("seotranslate_switcher");
                $this->translator->init();
            }
            //echo $this->options['seotranslate_ms_app_id'];
            ?>
            <div class="wrap">
                <div id="icon-options-general" class="icon32"><br /></div>
                <h2><?php _e('SEO Translate plugin ', 'seotranslate');
            echo SEOTRANSLATE_VERSION; ?> </h2>
                <?php
                global $wpdb;
//                $chars = $wpdb->get_results("show variables like 'char%'");
                $tableInfo = $wpdb->get_results("SHOW TABLE STATUS FROM " . DB_NAME . " WHERE Name = '{$this->translator->table}'");
                $allowedChars = array(
                    'ucs2_bin',
                    'ucs2_czech_ci',
                    'ucs2_danish_ci',
                    'ucs2_esperanto_ci',
                    'ucs2_estonian_ci',
                    'ucs2_general_ci',
                    'ucs2_hungarian_ci',
                    'ucs2_icelandic_ci',
                    'ucs2_latvian_ci',
                    'ucs2_lithuanian_ci',
                    'ucs2_persian_ci',
                    'ucs2_polish_ci',
                    'ucs2_roman_ci',
                    'ucs2_romanian_ci',
                    'ucs2_slovak_ci',
                    'ucs2_slovenian_ci',
                    'ucs2_spanish_ci',
                    'ucs2_spanish2_ci',
                    'ucs2_swedish_ci',
                    'ucs2_turkish_ci',
                    'ucs2_unicode_ci',
                    'utf8_bin',
                    'utf8_czech_ci',
                    'utf8_danish_ci',
                    'utf8_esperanto_ci',
                    'utf8_estonian_ci',
                    'utf8_general_ci',
                    'utf8_hungarian_ci',
                    'utf8_icelandic_ci',
                    'utf8_latvian_ci',
                    'utf8_lithuanian_ci',
                    'utf8_persian_ci',
                    'utf8_polish_ci',
                    'utf8_roman_ci',
                    'utf8_romanian_ci',
                    'utf8_slovak_ci',
                    'utf8_slovenian_ci',
                    'utf8_spanish_ci',
                    'utf8_spanish2_ci',
                    'utf8_swedish_ci',
                    'utf8_turkish_ci',
                    'utf8_unicode_ci'
                );
//                var_dump($tableInfo);exit;
                foreach ($tableInfo as $val) {
                    if (!in_array($val->Collation, $allowedChars)) {
                        ?>
                        <div class="updated">
                            <p>Your database is not using the utf8 character set. As a result, non-latin languages may not display correctly</p>
                            <p>Your current character set is [<strong><?php echo $val->Collation; ?></strong>]. Learn more about character sets <a href="http://dev.mysql.com/doc/refman/5.6/en/charset-syntax.html" target="_blank">here</a> </p>
                            <?php /* <p>Your database has problem with utf8 and can't work properly</p>
                              <p>Please set <strong><?php echo $val->Variable_name; ?></strong> to <strong>utf8</strong>. Now it has <strong><?php echo $val->Value; ?></strong></p> */ ?>
                        </div>
                        <?php
                    }
                }
                if ($this->sitemap_is_final) {
                    ?>
                    <div class="updated">
                        <p>Your version of 'GoogleSitemapGenerator' is not capable with this plugin. Please download the latest sitemap version <a href ="http://wordpress.org/extend/plugins/google-sitemap-generator/">here</a>.</p>
                    </div>
                <?php } ?>

                <form method="post" name="seotranslate" action="options.php">
                    <?php settings_fields('seo-translate'); ?>
                    <?php //if ($this->isValidId()) { ?>
                    <?php if (1) { ?>


                        <?php //print_r($this->options);        ?>

                        <h3><?php _e('Language Settings', 'seotranslate'); ?></h3>
                        <p><?php _e('My blog is written in: ', 'seotranslate'); ?><select name="seotranslate_default">
                                <?php ?>
                                <?php
                                $langs = $this->options['seotranslate_lang_list'];

                                if (is_array($langs)) {
                                    asort($langs);
                                    foreach ($langs as $code => $name) {
                                        echo "<option value='$code' " . selected($code, $this->options['seotranslate_default'], false) . ">$name ($code)</option>";
                                    }
                                    unset($langs[$this->options['seotranslate_default']]);
                                }
                                ?>

                            </select></p>

                        <h3><?php _e('Translate my site to the following languages', 'seotranslate'); ?> <span class="link">[<a href="#" onclick="javascript:seotranslate_all_inputs('langs_checkbox'); return false;"><?php _e('select all', 'seotranslate'); ?></a>]</span></h3>

                        <div class="right-panel">
                            <iframe src="http://www.facebook.com/plugins/likebox.php?href=http%3A%2F%2Fwww.facebook.com%2Fpages%2FSEO-Translate%2F117725328314804&amp;width=292&amp;colorscheme=light&amp;show_faces=true&amp;border_color&amp;stream=false&amp;header=false&amp;height=260" scrolling="no" frameborder="0" style="border:none; overflow:hidden; width:292px; height:260px;" allowTransparency="true"></iframe>
                            <div class="about">
                                <h3><?php _e('About SEO Translate Plugin', 'seotranslate'); ?></h3>
                                <div class="text">
                                    <?php _e('For support and more information, visit <a href="http://www.seotranslate.com" target="_blank">www.seotranslate.com</a>', 'seotranslate'); ?>
                                </div>
                            </div>
                        </div>
                        <?php // print_r($langs)        ?>
                        <ul class="langs">
                            <?php
                            /* $trans = new MS_Translate_API("en",false,"","FDF42509F770A67BFF26D2E11C2B1FED09944B32");
                              $var1 =  $trans->translate("Hello World", "Ru");
                              $langs = $trans->native_languages();
                              print_r($langs);exit; */

                            $count = 1;
                            if (is_array($langs)) {
                                foreach ($langs as $code => $name) {
                                    ?>
                                    <li><input class="langs_checkbox" type="checkbox" name="seotranslate_lang[<?php echo $code; ?>]" <?php checked(isset($this->options['seotranslate_lang'][$code])) ?> value="<?php echo $count++; ?>" /><img src="<?php echo $this->plugin_URL . '/flags/' . $code . '.png'; ?>" alt="<?php echo $name; ?>" /> <?php echo "$name ($code)"; ?></li>
                                    <?php
                                }
                            }

                            $validId = $this->isValidId();
                            ?></ul><div style="clear:both;"></div>
                        <script type="text/javascript">
                            function seotranslate_all_inputs(className) {
                                var hasClassName = new RegExp("(?:^|\\s)" + className + "(?:$|\\s)");
                                var elements = document.getElementsByTagName('input');
                                var element;
                                for (var i = 0; (element = elements[i]) != null; i++) {
                                    var elementClass = element.className;
                                    if (elementClass && elementClass.indexOf(className) != -1 && hasClassName.test(elementClass))
                                        element.checked = true;
                                }
                            }
                        </script>
                        <h3><?php _e('Display languages to my audience', 'seotranslate'); ?></h3>
                        <p><input type="checkbox" name="seotranslate_switcher[above]" <?php
                checked(isset($this->options['seotranslate_switcher']['above']));
                            ?> /> <?php _e('Above every page on my site', 'seotranslate'); ?></p>
                        <p><input type="checkbox" name="seotranslate_switcher[post]" <?php
                  checked(isset($this->options['seotranslate_switcher']['post']));
                            ?> /> <?php _e('Before blog Posts', 'seotranslate'); ?></p>
                        <p><input type="checkbox" name="seotranslate_switcher[page]" <?php
                  checked(isset($this->options['seotranslate_switcher']['page']));
                            ?> /> <?php _e('Before Pages', 'seotranslate'); ?></p>
                        <span class="description"><?php printf(__('Add a list of languages to your sidebar from the %s settings', 'seotranslate'), '<a href="widgets.php">' . __('Widgets') . '</a>'); ?></span>
                    <?php } ?>
                    <h3 class="optimize"><a href="http://www.bing.com/developers/appids.aspx" class="<?php echo ( $validId ) ? 'complete' : 'incomplete' ?>">.</a><?php _e('Required: Enter a Microsoft AppID (it\'s free and takes just two minutes)', 'seotranslate'); ?></h3>
                    <p>Getting a unique Microsoft API key increases the transfer rate and reliability when translating your site</p>
                    <p><strong>Three simple steps:</strong></p>
                    <ol>
                        <li>Visit <a href="http://www.bing.com/developers/createapp.aspx" target="_blank">Bing Developer Center</a></li>
                        <li>Sign in and fill out the "Create a new AppId" form - takes just 2 minutes</li>
                        <li>Enter your AppID below and press "Save Changes"</li>
                    </ol>

                    <p><input type="text" class="optimize_input" name="seotranslate_ms_app_id" width="45" value="<?php echo $this->options['seotranslate_ms_app_id']; ?>"/></p>
                    <input type="hidden" class="optimize_input" name="seotranslate_ms_app_id_old" width="45" value="<?php echo $this->options['seotranslate_ms_app_id']; ?>"/>
                    <?php if (!$validId) { ?>
                        <?php if ($this->options['seotranslate_ms_app_id'] == "") { ?>
                            <div class="error">Please fix the following error. Enter a Microsoft AppID.</div>
                        <?php } else { ?>
                            <div class="error">Please fix the following error. Your Microsoft AppID is invalid. Please enter a new AppID.</div>
                        <?php } ?>

                    <?php } ?>

                    <p class="submit">
                        <input type="submit" class="button-primary" value="<?php _e('Save Changes') ?>" />
                    </p>

                </form>
            </div>
            <script type="text/javascript">
                var uvOptions = {};
                (function() {
                    var uv = document.createElement('script'); uv.type = 'text/javascript'; uv.async = true;
                    uv.src = ('https:' == document.location.protocol ? 'https://' : 'http://') + 'widget.uservoice.com/YyQhio2T1H1xpGpdc4IQQ.js';
                    var s = document.getElementsByTagName('script')[0]; s.parentNode.insertBefore(uv, s);
                })();
            </script>
            <?php
        }

        function isValidId() {

            $intalls = get_option('intall');

            if ($intalls) {

                $locale = get_option('seotranslate_default', $this->options['seotranslate_default']);
                $exclude = get_option('seotranslate_exclude', $this->options['seotranslate_exclude']);
                $ms_app_id = get_option('seotranslate_ms_app_id', $this->options['seotranslate_ms_app_id']);

                if ($ms_app_id == '') {
                    $return = false;
                    //$return = true;
                } else {

                    require_once(dirname(__FILE__) . '/includes/class.easydebug.php');
                    require_once(dirname(__FILE__) . '/includes/class.ms_translate_api.php');
                    $this->translator = new MS_Translate_API($locale, false, $exclude, $ms_app_id);
                    $result = $this->translator->api_request(array('method' => 'GetLanguagesForTranslate'));
                    //print_r($result);exit;
                    //$t = $this->translator->translate("hello world","ru");
                    //print_r($t);exit;

                    if (is_array($result)) {
                        $return = true;
                    } else {
                        $return = false;
                        // $return = true;
                    }
                }
            } else {
                $return = true;
            }
            return $return;
        }

        function clearCache() {
            $this->translator->truncate();
        }

    }

}
if (!isset($SEOTranslate_plugin_instance))
    $SEOTranslate_plugin_instance = new SEOTranslate();
    

