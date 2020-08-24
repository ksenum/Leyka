<?php if( !defined('WPINC') ) die;

abstract class Leyka_Options_Allocator extends Leyka_Singleton {

    protected static $_instance;

    protected $_tabs = array();

    protected function __construct() {
        $this->_tabs = apply_filters('leyka_settings_tabs', array(
            'payment' => __('Payment options', 'leyka'),
            'beneficiary' => __('My data', 'leyka'),
            'view' => __('Campaign view', 'leyka'),
            'email' => __('Notifications', 'leyka'),
            'technical' => __('Tech settings', 'leyka'),
            'additional' => __('For developers', 'leyka'),
        ));
    }

    /** Can't make instance of the root/factory class, so overload the get_instance() */
    public static function get_instance(array $params = array()) {
        return self::get_allocator();
    }

    public function get_tabs() {
        return $this->_tabs;
    }

    abstract public function get_tab_options($tab_id);

    /**
     * @return Leyka_Options_Allocator
     * @throws Exception
     */
    public static function get_allocator() {

        $country_id = leyka_options()->opt_safe('receiver_country');

        // Specific Allocator class:
        $file_path = LEYKA_PLUGIN_DIR.'inc/settings/allocators/leyka-class-'.$country_id.'-options-allocator.php';

        if(in_array($country_id, array('by', 'ua',))) { // Some countries allocators are descendants of the Ru allocator
            require_once LEYKA_PLUGIN_DIR.'inc/settings/allocators/leyka-class-ru-options-allocator.php';
        }

        if(file_exists($file_path)) {
            require_once($file_path);
        } else {
            throw new Exception(
                sprintf(
                    __("Allocators Factory error: Can't find Allocator script by given country ID (%s, %s)"),
                    $country_id, $file_path
                ), 600
            );
        }

        $allocator_class = 'Leyka_'.mb_ucfirst($country_id).'_Options_Allocator';
        if(class_exists($allocator_class)) {
            return new $allocator_class();
        } else {
            throw new Exception(
                sprintf(__('Allocators Factory error: wrong allocator class given (%s)'), $allocator_class), 601
            );
        }

    }

}

/** @return Leyka_Options_Allocator */
function leyka_opt_alloc() {
    return Leyka_Options_Allocator::get_instance();
}