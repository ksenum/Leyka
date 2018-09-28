<?php if( !defined('WPINC') ) die;
/**
 * Leyka Init plugin setup Wizard class.
 **/

class Leyka_Yakassa_Wizard_Settings_Controller extends Leyka_Wizard_Settings_Controller {

    protected static $_instance = null;
    
    protected function _setAttributes() {

        $this->_id = 'yakassa';
        $this->_title = 'Мастер подключения Яндекс Кассе';

    }

    protected function _loadCssJs() {

        wp_enqueue_script('leyka-easy-modal', LEYKA_PLUGIN_BASE_URL . 'js/jquery.easyModal.min.js', array(), false, true);
        
        wp_localize_script('leyka-admin', 'leyka_wizard_yakassa', array(
        ));

        parent::_loadCssJs();

    }

    protected function _setSections() {

        // The main Yandex Kassa settings section:
        $section = new Leyka_Settings_Section('yakassa', 'Яндекс Касса');

        $step = new Leyka_Settings_Step('init',  $section->id, 'Яндекс Касса');
        $step->addBlock(new Leyka_Text_Block(array(
            'id' => 'step-intro-text',
            'text' => 'Приём электронных платежей. Платежи с банковских карт Mastercard, Maestro, Visa, «Мир» и другие способы.  Касса подходит для ИП и юрлиц, работает в России и за её пределами.',
        )))->addBlock(new Leyka_Text_Block(array(
            'id' => 'yakassa-payment-cards-icons',
            'template' => 'yakassa_payment_cards_icons',
        )))->addTo($section);

        $step = new Leyka_Settings_Step('start_connection',  $section->id, 'Начало подключения');
        $step->addBlock(new Leyka_Custom_Setting_Block(array(
            'id' => 'start-connection',
            'custom_setting_id' => 'yakassa_start_connection',
            'field_type' => 'custom_yakassa_start_connection',
            'keys' => array('org_inn'),
            'rendering_type' => 'template',
        )))->addHandler(array($this, 'handleSaveOptions'))->addTo($section);
        
        $step = new Leyka_Settings_Step('general_info',  $section->id, 'Заполняем общие сведения');
        $step->addBlock(new Leyka_Text_Block(array(
            'id' => 'general-info',
            'template' => 'yakassa_general_info',
        )))->addTo($section);
        
        $step = new Leyka_Settings_Step('contact_info',  $section->id, 'Заполняем Контактную информацию');
        $step->addBlock(new Leyka_Text_Block(array(
            'id' => 'contact-info',
            'template' => 'yakassa_contact_info',
        )))->addTo($section);

        $step = new Leyka_Settings_Step('gos_reg',  $section->id, 'Сведения о государственной регистрации');
        $step->addBlock(new Leyka_Custom_Setting_Block(array(
            'id' => 'gos-reg',
            'custom_setting_id' => 'yakassa_gos_reg',
            'field_type' => 'custom_yakassa_gos_reg',
            'keys' => array('org_address'),
            'rendering_type' => 'template',
        )))->addHandler(array($this, 'handleSaveOptions'))->addTo($section);

        $step = new Leyka_Settings_Step('bank_account',  $section->id, 'Банковский счет');
        $step->addBlock(new Leyka_Custom_Setting_Block(array(
            'id' => 'bank-account',
            'custom_setting_id' => 'yakassa_bank_account',
            'field_type' => 'custom_yakassa_bank_account',
            'keys' => array('org_bank_bic', 'org_bank_account'),
            'rendering_type' => 'template',
        )))->addHandler(array($this, 'handleSaveOptions'))->addTo($section);
        
        $step = new Leyka_Settings_Step('boss_info',  $section->id, 'Заполняем Данные руководителя');
        $step->addBlock(new Leyka_Text_Block(array(
            'id' => 'boss-info',
            'template' => 'yakassa_boss_info',
        )))->addTo($section);
        
        $step = new Leyka_Settings_Step('upload_documents',  $section->id, 'Заполняем Данные руководителя');
        $step->addBlock(new Leyka_Text_Block(array(
            'id' => 'upload-documents',
            'template' => 'yakassa_upload_documents',
        )))->addTo($section);
        
        $step = new Leyka_Settings_Step('send_form',  $section->id, 'Отправляем анкету');
        $step->addBlock(new Leyka_Text_Block(array(
            'id' => 'send-form',
            'template' => 'yakassa_send_form',
        )))->addTo($section);

        $step = new Leyka_Settings_Step('sign_documents',  $section->id, 'Подписываем документы');
        $step->addBlock(new Leyka_Text_Block(array(
            'id' => 'sign-documents',
            'template' => 'yakassa_sign_documents',
        )))->addTo($section);
        
        $step = new Leyka_Settings_Step('settings',  $section->id, 'Настройки');
        $step->addBlock(new Leyka_Text_Block(array(
            'id' => 'settings',
            'template' => 'yakassa_settings',
        )))->addTo($section);
        
        $this->_sections[$section->id] = $section;
        
        // Final Section:
        $section = new Leyka_Settings_Section('final', 'Завершение');

        $step = new Leyka_Settings_Step('yakassa_final', $section->id, 'Поздравляем!', array('header_classes' => 'greater',));
        $step->addBlock(new Leyka_Text_Block(array(
            'id' => 'step-intro-text',
            'text' => '<p>Вы подключили Яндекс Деньги. Стали доступны платежи с помощью банковских карт, Яндекс.Деньги, Сбербанк Онлайн (интернет-банк Сбербанка), Альфа-Клик (интернет-банк Альфа-Банка), криптограмма Apple Pay, криптограмма Google Pay, QIWI Кошелек, Webmoney, баланс мобильного телефона</p>
<p>Вы подключили Яндекс Деньги. Стали доступны платежи с помощью банковских карт, Яндекс.Деньги, Сбербанк Онлайн (интернет-банк Сбербанка), Альфа-Клик (интернет-банк Альфа-Банка), криптограмма Apple Pay, криптограмма Google Pay, QIWI Кошелек, Webmoney, баланс мобильного телефона
Поделитесь последней вашей кампанией с друзьями и попросите их отправить пожертвование. Так вы сможете протестировать новый метод оплаты</p>',
        )))->addBlock(new Leyka_Text_Block(array(
            'id' => 'yakassa-final',
            'template' => 'yakassa_final',
        )))->addTo($section);

        $this->_sections[$section->id] = $section;
        // Final Section - End

    }

    protected function _initNavigationData() {

        $this->_navigation_data = array(
            array(
                'section_id' => 'yakassa',
                'title' => 'Яндекс Касса',
                'url' => '',
                'steps' => array(
                    array(
                        'step_id' => 'start_connection',
                        'title' => 'Начало подключения',
                        'url' => '',
                    ),
                    array(
                        'step_id' => 'general_info',
                        'title' => 'Общие сведния',
                        'url' => '',
                    ),
                    array(
                        'step_id' => 'contact_info',
                        'title' => 'Контактная информация',
                        'url' => '',
                    ),
                    array(
                        'step_id' => 'gos_reg',
                        'title' => 'Гос.регистрация',
                        'url' => '',
                    ),
                    array(
                        'step_id' => 'bank_account',
                        'title' => 'Банковский счет',
                        'url' => '',
                    ),
                    array(
                        'step_id' => 'boss_info',
                        'title' => 'Данные руководителя',
                        'url' => '',
                    ),
                    array(
                        'step_id' => 'upload_documents',
                        'title' => 'Загрузка документов',
                        'url' => '',
                    ),
                    array(
                        'step_id' => 'send_form',
                        'title' => 'Отправляем анкету',
                        'url' => '',
                    ),
                    array(
                        'step_id' => 'sign_documents',
                        'title' => 'Подписываем документы',
                        'url' => '',
                    ),
                    array(
                        'step_id' => 'settings',
                        'title' => 'Настройки',
                        'url' => '',
                    ),
                ),
            ),
            array(
                'section_id' => 'final',
                'title' => 'Завершение',
                'url' => '',
            ),
        );

    }
    
    public function getNavigationData() {

        $current_navigation_data = $this->_navigation_data;
        $current_step_full_id = $this->getCurrentStep()->full_id;

        switch($current_step_full_id) {
            case 'yakassa-init': $navigation_position = 'yakassa'; break;
            default: $navigation_position = $current_step_full_id;
        }

        return $navigation_position ?
            $this->_processNavigationData($navigation_position) :
            $current_navigation_data;

    }

    public function getSubmitData($component = null) {

        $step = $component && is_a($component, 'Leyka_Settings_Step') ? $component : $this->current_step;
        $submit_settings = array(
            'next_label' => 'Продолжить',
            'next_url' => true,
            'prev' => 'Вернуться на предыдущий шаг',
        );

        if($step->next_label) {
            $submit_settings['next_label'] = $step->next_label;
        }

        if($step->section_id === 'yakassa' && $step->id === 'init') {
            $submit_settings['prev'] = false;   // I. e. the Wizard shouln't display the back link
        } else if($step->section_id === 'final') {

            $submit_settings['next_label'] = 'Перейти в Панель управления';
            $submit_settings['next_url'] = admin_url('admin.php?page=leyka');

        }

        return $submit_settings;

    }
    
    public function handleSaveOptions(array $step_settings) {
        
        $errors = array();
        
        foreach($step_settings as $option_id => $value) {
            leyka_save_option($option_id);
        }
        
        return !empty($errors) ? $errors : true;
    
    }
    
}