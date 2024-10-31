<?php

/*
 *  (c) , 2011 Wott (http://wott.info/ , wotttt@gmail.com) 
 */

class SEOTranslateWidget extends WP_Widget {
	function SEOTranslateWidget () {
		$widget_ops = array('classname' => 'seotranslate_switcher seotranslate_widget_switcher', 'description' => __( 'Add language switcher to sidebar or footer','seotranslate') );
		parent::WP_Widget( false, __('SEOTranslate Language Switcher','seotranslate') , $widget_ops);
	}

	function widget( $args, $instance ) {
		global $SEOTranslate_plugin_instance;
		extract($args);
		$title = apply_filters('widget_title', empty($instance['title']) ? __('Languages Available','seotranslate') : $instance['title'], $instance, $this->id_base);
		$flags = $instance['flags'] ? 1 : 0;

		echo $before_widget;
		if ( $title )
			echo $before_title . $title . $after_title;
		?>
			<ul>
			<?php echo $SEOTranslate_plugin_instance->switcher('widget', 'li', $flags, 'class="seotranslate-notranslate"'); ?>
			</ul>
		<?php

		echo $after_widget;			
	}		

	function update( $new_instance, $old_instance ) {
		$instance = $old_instance;
		$new_instance = wp_parse_args( (array) $new_instance, array( 'title' => '', 'flags' => 0, ) );
		$instance['title'] = strip_tags($new_instance['title']);
		$instance['flags'] = $new_instance['flags'] ? 1 : 0;

		return $instance;
	}

	function form( $instance ) {
		$instance = wp_parse_args( (array) $instance, array( 'title' => '', 'flags' => 0 ) );
		$title = strip_tags($instance['title']);
		?>
		<p><label for="<?php echo $this->get_field_id('title'); ?>"><?php _e('Title:'); ?></label> <input class="widefat" id="<?php echo $this->get_field_id('title'); ?>" name="<?php echo $this->get_field_name('title'); ?>" type="text" value="<?php echo esc_attr($title); ?>" /></p>
		<p>
			<input class="checkbox" type="checkbox" <?php checked($instance['flags']) ?> id="<?php echo $this->get_field_id('flags'); ?>" name="<?php echo $this->get_field_name('flags'); ?>" /> <label for="<?php echo $this->get_field_id('flags'); ?>"><?php _e('Display flags','seotranslate'); ?></label>
		</p>
		<?php
	}

}
?>
