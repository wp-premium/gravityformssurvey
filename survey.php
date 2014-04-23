<?php
/*
Plugin Name: Gravity Forms Survey Add-On
Plugin URI: http://www.gravityforms.com
Description: Survey Add-on for Gravity Forms
Version: 2.1
Author: Rocketgenius
Author URI: http://www.rocketgenius.com

------------------------------------------------------------------------
Copyright 2012-2013 Rocketgenius Inc.

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA 02111-1307 USA
*/


//------------------------------------------

if (class_exists("GFForms")) {
    GFForms::include_addon_framework();
    add_filter('gform_export_field_value', array('GFSurvey', 'display_export_field_value'), 10, 4);

    class GFSurvey extends GFAddOn {

        protected $_version = "2.1";
        protected $_min_gravityforms_version = "1.8.4.9";
        protected $_slug = "gravityformssurvey";
        protected $_path = "gravityformssurvey/survey.php";
        protected $_full_path = __FILE__;
        protected $_url = "http://www.gravityforms.com";
        protected $_title = "Gravity Forms Survey Add-On";
        protected $_short_title = "Survey";

        // Members plugin integration
        protected $_capabilities = array("gravityforms_survey", "gravityforms_survey_uninstall", "gravityforms_survey_results");

        // Permissions
        protected $_capabilities_settings_page = "gravityforms_survey";
        protected $_capabilities_form_settings = "gravityforms_survey";
        protected $_capabilities_uninstall = "gravityforms_survey_uninstall";

        protected $_enable_rg_autoupgrade = true;

        private $_form_meta_by_id = array();

        private static $_instance = null;

        public static function get_instance() {
            if (self::$_instance == null) {
                self::$_instance = new GFSurvey();
            }

            return self::$_instance;
        }

        private function __clone() { } /* do nothing */

        protected function scripts() {
            $scripts = array(
                array("handle"   => "gsurvey_form_editor_js",
                      "src"      => $this->get_base_url() . "/js/gsurvey_form_editor.js",
                      "version"  => $this->_version,
                      "deps"     => array("jquery"),
                      "callback" => array($this, "localize_scripts"),
                      "enqueue"  => array(
                          array("admin_page" => array("form_editor")),
                      )
                ),
                array(
                    "handle"  => "gsurvey_js",
                    "src"     => $this->get_base_url() . "/js/gsurvey.js",
                    "version" => $this->_version,
                    "deps"    => array("jquery", "jquery-ui-sortable"),
                    "enqueue" => array(
                        array("admin_page" => array("form_editor", "results", "entry_view", "entry_detail", "entry_edit")),
                        array("field_types" => array("survey"))
                    )
                )
            );

            return array_merge(parent::scripts(), $scripts);
        }

        protected function styles() {

            $styles = array(
                array("handle"  => "gsurvey_form_editor_css",
                      "src"     => $this->get_base_url() . "/css/gsurvey_form_editor.css",
                      "version" => $this->_version,
                      "enqueue" => array(
                          array("admin_page" => array("form_editor")),
                      )
                ),
                array("handle"  => "gsurvey_css",
                      "src"     => $this->get_base_url() . "/css/gsurvey.css",
                      "version" => $this->_version,
                      "media" => "screen",
                      "enqueue" => array(
                          array("admin_page" => array("form_editor", "results", "entry_view", "entry_detail", "entry_edit")),
                          array("field_types" => array("survey")
                              )
                      )
                )
            );

            return array_merge(parent::styles(), $styles);
        }

        protected function init_admin() {

            parent::init_admin();

            // form editor
            add_filter('gform_add_field_buttons', array($this, 'add_survey_field'));
            add_filter('gform_field_type_title', array($this, 'assign_title'), 10, 2);
            add_action('gform_field_standard_settings', array($this, 'survey_field_settings'), 10, 2);
            add_filter('gform_tooltips', array($this, 'add_survey_tooltips'));


            // merge tags
            add_filter('gform_admin_pre_render', array($this, 'add_merge_tags'));


            // display results on entry list
            add_filter('gform_entries_field_value', array($this, 'display_entries_field_value'), 10, 4);

            // declare arrays on form import
            add_filter('gform_import_form_xml_options', array($this, 'import_file_options'));

            // contacts
            add_filter("gform_contacts_tabs_contact_detail", array($this, 'add_tab_to_contact_detail'), 10, 2);
            add_action("gform_contacts_tab_survey", array($this, 'contacts_tab'));

        }

        protected function init_ajax() {

            parent::init_ajax();

            // placeholder

        }

        protected function init_frontend() {

            parent::init_frontend();

            add_filter('gform_validation', array($this, 'validation'));

            // merge tags
            add_filter('gform_merge_tag_filter', array($this, 'merge_tag_filter'), 10, 5);
            add_filter('gform_replace_merge_tags', array($this, 'replace_merge_tags'), 10, 7);

            // Mailchimp Add-On integration
            add_filter("gform_mailchimp_field_value", array($this, 'display_entries_field_value'), 10, 4);

            // Aweber Add-On integration
            add_filter("gform_aweber_field_value", array($this, 'display_entries_field_value'), 10, 4);

            // Campaign Monitor Add-On integration
            add_filter("gform_campaignmonitor_field_value", array($this, 'display_entries_field_value'), 10, 4);

            // Zapier Add-On integration
            add_filter("gform_zapier_field_value", array($this, 'display_entries_field_value'), 10, 4);
        }

        public function init() {

            parent::init();

            //------------------- both outside and inside admin context ------------------------

            // render field
            add_filter('gform_field_input', array($this, 'get_survey_field_content'), 10, 5);

            // add a special class to likert fields so we can identify them later
            add_action('gform_field_css_class', array($this, 'add_custom_class'), 10, 3);

            // display survey results on entry detail
            add_filter('gform_entry_field_value', array($this, 'display_survey_fields_on_entry_detail'), 10, 4);

            // conditional logic filters
            add_filter('gform_entry_meta_conditional_logic_confirmations', array($this, 'conditional_logic_filters'), 10, 3);
            add_filter('gform_entry_meta_conditional_logic_notifications', array($this, 'conditional_logic_filters'), 10, 3);

        } // end function init


        public function get_results_page_config() {
            return array(
                "title" => "Survey Results",
                "capabilities" => array("gravityforms_survey_results"),
                "callbacks" => array(
                    "fields" => array($this, "results_fields"),
                    "filters" => array($this, "results_filters")
                )
            );
        }

        public function results_filters($filters, $form){
            $fields = $form["fields"];
            foreach($fields as $field){
                $type = GFFormsModel::get_input_type($field);
                if("likert" == $type && rgar($field, "gsurveyLikertEnableScoring"))
                    return $filters;
            }


            // scoring is not enabled on any of the fields so remove the score from the filters
           foreach($filters as $key => $filter){
               if($filter["key"] == "gsurvey_score")
                unset($filters[$key]);
           }

            return $filters;
        }

        public function results_fields($form){
            return GFCommon::get_fields_by_type($form, array("survey"));
        }

        //--------------  Front-end UI functions  ---------------------------------------------------

        public function validation($validation_result) {
            $form = $validation_result["form"];

            $survey_fields = GFCommon::get_fields_by_type($form, array('survey'));

            if (empty ($survey_fields))
                return $validation_result;

            foreach ($form["fields"] as &$field) {
                $input_type = GFFormsModel::get_input_type($field);
                if ("likert" == $input_type && rgar($field, "gsurveyLikertEnableMultipleRows") && rgar($field, "isRequired")) {
                    $is_hidden    = RGFormsModel::is_field_hidden($form, $field, array());
                    $field_page   = $field['pageNumber'];
                    $current_page = rgpost('gform_source_page_number_' . $form['id']) ? rgpost('gform_source_page_number_' . $form['id']) : 1;
                    if ($field_page != $current_page || $is_hidden)
                        continue;

                    // loop through responses to make sure all rows have values
                    $incomplete = false;
                    $rows       = rgar($field, "gsurveyLikertRows");
                    $i          = 1;
                    foreach ($rows as $row) {
                        if ($i % 10 == 0) //hack to skip numbers ending in 0. so that 5.1 doesn't conflict with 5.10
                        $i++;
                        $field_id    = $field['id'] . "_" . (string)((int)$i++);
                        $field_value = rgpost("input_{$field_id}");
                        if (empty($field_value)) {
                            $incomplete = true;
                            break;
                        }
                    }

                    if ($incomplete) {
                        $field["failed_validation"]    = true;
                        $field["validation_message"]   = rgar($field, "errorMessage") ? rgar($field, "errorMessage") : __("This field is required");
                        $validation_result["is_valid"] = false;
                    }

                    continue;
                }
            }

            //Assign modified $form object back to the validation result
            $validation_result["form"] = $form;

            return $validation_result;

        }

        public function merge_tag_filter($value, $merge_tag, $options, $field, $raw_value) {
            if ($field["type"] != "survey")
                return $value;

            $input_type = GFFormsModel::get_input_type($field);
            if ($input_type == "likert" && rgar($field, "gsurveyLikertEnableMultipleRows")) {


                //replacing value with text
                if (empty($value)) {
                    $new_value = "<ul class='gsurvey-likert-entry'>";
                    $i         = 0;
                    foreach ($raw_value as $v) {
                        $row_text = $this->get_likert_row_text($field, $i++);
                        $col_text = $this->get_likert_column_text($field, $v);
                        $new_value .= sprintf("<li>%s: %s</li>", $row_text, $col_text);
                    }
                    $new_value .= "</ul>";

                } else {
                    $new_value = $this->get_likert_column_text($field, $value);
                }


            } elseif ($input_type == "rank" && is_array($field["choices"])) {
                $ordered_values = explode(",", $value);
                $new_value      = "<ol class='gsurvey-rank-entry'>";
                foreach ($ordered_values as $ordered_value) {
                    $ordered_label = GFCommon::selection_display($ordered_value, $field, $currency = "", $use_text = true);
                    $new_value .= sprintf("<li>%s</li>", $ordered_label);
                }
                $new_value .= "</ol>";
            } else {
                $new_value = GFFormsModel::get_choice_text($field, $value);
            }


            return $new_value;
        }

        public function get_survey_field_content($content, $field, $value, $lead_id, $form_id, $lead = null) {
            if ($field["type"] != "survey")
                return $content;
            $sub_type = rgar($field, "inputType");
            switch ($sub_type) {
                case "likert" :
                    $multiple_rows = rgar($field, "gsurveyLikertEnableMultipleRows");
                    $field_id      = $field["id"];
                    $num_rows      = $multiple_rows ? count($field["gsurveyLikertRows"]) : 1;
                    $table_id      = "id='input_{$form_id}_{$field_id}'";

                    $content = "<div class='ginput_container'>";
                    $content .= "<table {$table_id} class='gsurvey-likert'>";
                    $content .= "<tr>";
                    if ($multiple_rows)
                        $content .= "<td class='gsurvey-likert-row-label'></td>";

                    $disabled_text = ((IS_ADMIN && RG_CURRENT_VIEW != "entry") || (IS_ADMIN && RG_CURRENT_VIEW == "entry" && "edit" != rgpost("screen_mode"))) ? "disabled='disabled'" : "";
                    foreach ($field["choices"] as $choice) {
                        $content .= "<td class='gsurvey-likert-choice-label'>";
                        $content .= $choice["text"];

                        $content .= "</td>";
                    }
                    $content .= "</tr>";
                    $row_id = 1;
                    for ($i = 1; $i <= $num_rows; $i++) {
                        //hack to skip numbers ending in 0. so that 5.1 doesn't conflict with 5.10
                        if ($row_id % 10 == 0)
                            $row_id++;

                        $row_text  = $field["gsurveyLikertRows"][$i - 1]["text"];
                        $row_value = $field["gsurveyLikertRows"][$i - 1]["value"];
                        $content .= "<tr>";
                        if ($multiple_rows)
                            $content .= "<td class='gsurvey-likert-row-label'>{$row_text}</td>";
                        $choice_id = 1;
                        foreach ($field["choices"] as $choice) {
                            //hack to skip numbers ending in 0. so that 5.1 doesn't conflict with 5.10
                            if ($choice_id % 10 == 0)
                                $choice_id++;
                            $id = $field["id"] . '_' . $row_id . "_" . $choice_id;

                            $field_value = $multiple_rows ? $row_value . ":" . $choice["value"] : $choice["value"];
                            $cell_class  = "gsurvey-likert-choice";
                            $checked     = "";
                            $selected    = false;
                            if (rgblank($value) && empty($lead)) {
                                $checked = "";
                            } else {

                                if ($multiple_rows) {
                                    $input_name = $field["id"] . "." . $row_id;
                                    if (is_array($value) && isset($value[$input_name])) {
                                        $response_value = $value[$input_name];
                                        $selected       = $response_value == $field_value ? true : false;
                                    } else {
                                        if ($lead == null)
                                            $lead = GFFormsModel::get_lead($lead_id);
                                        if (false === $lead) {
                                            $selected = false;
                                        } else {
                                            $response_col_val = $this->get_likert_col_val_for_row_from_entry($row_value, $field_id, $lead);
                                            $selected         = $response_col_val == $choice["value"] ? true : false;
                                        }
                                    }
                                } else {
                                    $selected = $choice["value"] == $value ? true : false;
                                }

                            }
                            if ($selected) {
                                $checked    = "checked='checked'";
                                $cell_class = $cell_class . " gsurvey-likert-selected";
                            }
                            $logic_event = (empty($field["conditionalLogicFields"]) || IS_ADMIN) ? "" : "onclick='gf_apply_rules(" . $field["formId"] . "," . GFCommon::json_encode($field["conditionalLogicFields"]) . ");'";
                            $tabindex    = GFCommon::get_tabindex();
                            $content .= "<td class='$cell_class'>";

                            if ($multiple_rows) { //
                                $input_id = sprintf("input_%d.%d", $field_id, $row_id);
                            } else {
                                $input_id = sprintf("input_%d", $field_id);
                            }
                            $content .= sprintf("<input name='$input_id' type='radio' $logic_event value='%s' %s id='choice_%s' $tabindex %s />", esc_attr($field_value), $checked, $id, $disabled_text);
                            $content .= "</td>";
                            $choice_id++;
                        }
                        $row_id++;
                        $content .= "</tr>";
                    }


                    $content .= "</table>";
                    $content .= '</div>';
                    break;
                case "rank" :

                    $field_id = $field["id"];
                    $input_id = "gsurvey-rank-{$form_id}-{$field_id}";
                    $content  = "<div class='ginput_container'>";
                    $content .= "<ul id='{$input_id}' class='gsurvey-rank'>";

                    $handle_image_url = $this->get_base_url() . "/images/arrow-handle.png";
                    $choice_id        = 0;
                    $count            = 1;
                    $choices          = array();
                    //if ((RG_CURRENT_VIEW == "entry" || (is_admin() && rgget("page")) == "gf_results") && false === empty($value)) {
                    if (false === empty($value)) {
                        $ordered_values = explode(",", $value);
                        foreach ($ordered_values as $ordered_value) {
                            $choices[] = array(
                                "value" => $ordered_value,
                                "text"  => RGFormsModel::get_choice_text($field, $ordered_value)
                            );
                        }
                    } else {
                        $choices = $field["choices"];
                    }

                    foreach ($choices as $choice) {
                        $id          = $field["id"] . '_' . $choice_id++;
                        $field_value = !empty($choice["value"]) || rgar($field, "enableChoiceValue") ? $choice["value"] : $choice["text"];

                        $content .= sprintf("<li data-index='{$choice_id}' class='gsurvey-rank-choice choice_%s' id='{$field_value}' ><img src='{$handle_image_url}'  alt='' />%s</li>", $id, $choice["text"]);
                        if (IS_ADMIN && RG_CURRENT_VIEW != "entry" && $count >= 5)
                            break;
                        $count++;

                    }
                    $content .= "</ul>";
                    $content .= sprintf("<input type='hidden' id='{$input_id}-hidden' name='input_%d' />", $field_id);
                    $content .= '</div>';
                    break;
                case "rating" :
                    $field_id      = $field["id"];
                    $div_id        = "input_{$form_id}_{$field_id}";
                    $disabled_text = (IS_ADMIN && RG_CURRENT_VIEW != "entry") ? "disabled='disabled'" : "";
                    $content       = "<div class='gsurvey-rating-wrapper'><div id='{$div_id}' class='gsurvey-rating'>";
                    $choice_id     = 0;
                    $count         = 1;
                    foreach ($field["choices"] as $choice) {
                        $id = $field["id"] . '_' . $choice_id++;

                        $field_value = !empty($choice["value"]) || rgar($field, "enableChoiceValue") ? $choice["value"] : $choice["text"];

                        if (rgblank($value) && RG_CURRENT_VIEW != "entry") {
                            $checked = rgar($choice, "isSelected") ? "checked='checked'" : "";
                        } else {
                            $checked = RGFormsModel::choice_value_match($field, $choice, $value) ? "checked='checked'" : "";
                        }
                        $logic_event = (empty($field["conditionalLogicFields"]) || IS_ADMIN) ? "" : "onclick='gf_apply_rules(" . $field["formId"] . "," . GFCommon::json_encode($field["conditionalLogicFields"]) . ");'";
                        $tabindex    = GFCommon::get_tabindex();

                        $choice_label = $choice["text"];
                        $content .= sprintf("<input name='input_%d' type='radio' $logic_event value='%s' %s id='choice_%s' $tabindex %s /><label for='choice_%s' title='%s'>%s</label>", $field_id, esc_attr($field_value), $checked, $id, $disabled_text, $id, $choice_label, $choice_label);

                        if (IS_ADMIN && RG_CURRENT_VIEW != "entry" && $count >= 5)
                            break;
                        $count++;

                    }
                    $content .= "</div></div>";

            }

            return $content;

        }

        public function get_likert_col_val_for_row_from_entry($row_value, $field_id, $entry) {
            foreach ($entry as $key => $value) {
                if (intval($key) != $field_id)
                    continue;
                if (false === strpos($value, ":"))
                    continue;
                list($row_id, $col_id) = explode(":", $value, 2);
                if ($row_value == $row_id)
                    return $col_id;
            }

            return false;
        }


        //--------------  Scripts & Styles  --------------------------------------------------


        public function localize_scripts() {

            // Get current page protocol
            $protocol = isset($_SERVER["HTTPS"]) ? 'https://' : 'http://';
            // Output admin-ajax.php URL with same protocol as current page
            $params = array(
                'ajaxurl'   => admin_url('admin-ajax.php', $protocol),
                'imagesUrl' => $this->get_base_url() . "/images"
            );
            wp_localize_script('gsurvey_form_editor_js', 'gsurveyVars', $params);

            //localize strings for the js file
            $strings = array(
                'firstChoice'      => __("First row", "gravityformssurvey"),
                'secondChoice'     => __("Second row", "gravityformssurvey"),
                'thirdChoice'      => __("Third row", "gravityformssurvey"),
                'fourthChoice'     => __("Fourth row", "gravityformssurvey"),
                'fifthChoice'      => __("Fifth row", "gravityformssurvey"),
                'dragToReOrder'    => __("Drag to re-order", "gravityformssurvey"),
                'addAnotherRow'    => __("Add another row", "gravityformssurvey"),
                'removeThisRow'    => __("Remove this row", "gravityformssurvey"),
                'addAnotherColumn' => __("Add another column", "gravityformssurvey"),
                'removeThisColumn' => __("Remove this column", "gravityformssurvey"),
                'columnLabel1'     => __("Strongly disagree", "gravityformssurvey"),
                'columnLabel2'     => __("Disagree", "gravityformssurvey"),
                'columnLabel3'     => __("Neutral", "gravityformssurvey"),
                'columnLabel4'     => __("Agree", "gravityformssurvey"),
                'columnLabel5'     => __("Strongly agree", "gravityformssurvey")

            );
            wp_localize_script('gsurvey_form_editor_js', 'gsurveyLikertStrings', $strings);

            //localize strings for the rank field
            $rank_strings = array(
                'firstChoice'  => __("First Choice", "gravityformssurvey"),
                'secondChoice' => __("Second Choice", "gravityformssurvey"),
                'thirdChoice'  => __("Third Choice", "gravityformssurvey"),
                'fourthChoice' => __("Fourth Choce", "gravityformssurvey"),
                'fifthChoice'  => __("Fifth Choice", "gravityformssurvey")
            );
            wp_localize_script('gsurvey_form_editor_js', 'gsurveyRankStrings', $rank_strings);

            //localize strings for the ratings field
            $rating_strings = array(
                'firstChoice'  => __("Excellent", "gravityformssurvey"),
                'secondChoice' => __("Pretty good", "gravityformssurvey"),
                'thirdChoice'  => __("Neutral", "gravityformssurvey"),
                'fourthChoice' => __("Not so great", "gravityformssurvey"),
                'fifthChoice'  => __("Terrible", "gravityformssurvey")
            );
            wp_localize_script('gsurvey_form_editor_js', 'gsurveyRatingStrings', $rating_strings);

        }

        public function localize_results_scripts() {

            $filter_fields    = rgget("f");
            $filter_types     = rgget("t");
            $filter_operators = rgget("o");
            $filter_values    = rgget("v");

            // Get current page protocol
            $protocol = isset($_SERVER["HTTPS"]) ? 'https://' : 'http://';
            // Output admin-ajax.php URL with same protocol as current page

            $vars = array(
                'ajaxurl'         => admin_url('admin-ajax.php', $protocol),
                'imagesUrl'       => $this->get_base_url() . "/images",
                'filterFields'    => $filter_fields,
                'filterTypes'     => $filter_types,
                'filterOperators' => $filter_operators,
                'filterValues'    => $filter_values
            );

            wp_localize_script('gsurvey_results_js', 'gresultsVars', $vars);

            $strings = array(
                'noFilters'         => __("No filters", "gravityformspolls"),
                'addFieldFilter'    => __("Add a field filter", "gravityformspolls"),
                'removeFieldFilter' => __("Remove a field filter", "gravityformspolls"),
                'ajaxError'         => __("Error retrieving results. Please contact support.", "gravityformspolls")
            );

            wp_localize_script('gsurvey_results_js', 'gresultsStrings', $strings);

        }


        //--------------  Admin functions  ---------------------------------------------------

        public function add_tab_to_contact_detail($tabs, $contact_id) {
            if ($contact_id > 0)
                $tabs[] = array("name" => 'survey', "label" => __("Survey Entries", "gravityformssurvey"));

            return $tabs;
        }

        public function contacts_tab($contact_id) {

            if (false === empty($contact_id)) :
                $search_criteria["status"]          = "active";
                $search_criteria["field_filters"][] = array("type" => "meta", "key" => "gcontacts_contact_id", "value" => $contact_id);
                $form_ids                           = array();
                $forms                              = GFFormsModel::get_forms(true);
                foreach ($forms as $form) {
                    $form_meta     = GFFormsModel::get_form_meta($form->id);
                    $survey_fields = GFCommon::get_fields_by_type($form_meta, array('survey'));
                    if (!empty($survey_fields))
                        $form_ids[] = $form->id;
                }


                if (empty($form_ids))
                    return;
                $entries = GFAPI::get_entries($form_ids, $search_criteria);

                if (empty($entries)) :
                    _e("This contact has not submitted any survey entries yet.", "gravityformssurvey"); else :
                    ?>
                    <h3><span><?php _e("Survey Entries", "gravityformssurvey") ?></span></h3>
                    <div>
                        <table id="gcontacts-entry-list" class="widefat">
                            <tr class="gcontacts-entries-header">
                                <td>
                                    <?php _e("Entry Id", "gravityformssurvey") ?>
                                </td>
                                <td>
                                    <?php _e("Date", "gravityformssurvey") ?>
                                </td>
                                <td>
                                    <?php _e("Form", "gravityformssurvey") ?>
                                </td>
                            </tr>
                            <?php


                            foreach ($entries as $entry) {
                                $form_id    = $entry["form_id"];
                                $form       = GFFormsModel::get_form_meta($form_id);
                                $form_title = rgar($form, "title");
                                $entry_id   = $entry["id"];
                                $entry_date = GFCommon::format_date(rgar($entry, "date_created"), false);
                                $entry_url  = admin_url("admin.php?page=gf_entries&view=entry&id={$form_id}&lid={$entry_id}");

                                ?>
                                <tr>
                                    <td>
                                        <a href="<?php echo $entry_url; ?>"><?php echo $entry_id; ?></a>
                                    </td>
                                    <td>
                                        <?php echo $entry_date; ?>
                                    </td>
                                    <td>
                                        <?php echo $form_title; ?>
                                    </td>


                                </tr>
                            <?php
                            }
                            ?>
                        </table>
                    </div>
                <?php
                endif;
            endif;

        }

        // by default all entry meta fields are included - so filter the unnecessary ones according to context
        public function conditional_logic_filters($filters, $form, $id) {
            $survey_fields = GFCommon::get_fields_by_type($form, array('survey'));
            if (empty($survey_fields))
                return $filters;

            if (false === $this->scoring_enabled($form))
                unset($filters["gsurvey_score"]);

            return $filters;
        }

        private function scoring_enabled($form){

            $survey_fields = GFCommon::get_fields_by_type($form, array('survey'));

            foreach($survey_fields as $field){
                $type = GFFormsModel::get_input_type($field);
                if("likert" == $type && rgar($field, "gsurveyLikertEnableScoring"))
                    return true;
            }
            return false;
        }

        public function get_entry_meta($entry_meta, $form_id) {
            if(empty($form_id))
                return $entry_meta;
            $form        = RGFormsModel::get_form_meta($form_id);
            $survey_fields = GFCommon::get_fields_by_type($form, array('survey'));
            if (false === empty ($survey_fields) && $this->scoring_enabled($form)) {

                $entry_meta['gsurvey_score']   = array(
                    'label'                      => 'Survey Score Total',
                    'is_numeric'                 => true,
                    'is_default_column'          => false,
                    'update_entry_meta_callback' => array($this, 'update_entry_meta'),
                    'filter'                     => array(
                        "operators" => array("is", "isnot", ">", "<")
                    )
                );


            }

            return $entry_meta;
        }

        public function update_entry_meta($key, $lead, $form) {
            $value   = "";

            if ($key == "gsurvey_score")
                $value = $this->get_survey_score($form, $lead);


            return $value;
        }

        public function get_survey_score($form, $entry){
            $survey_fields = GFCommon::get_fields_by_type($form, array('survey'));
            $score = 0;
            foreach ($survey_fields as $field) {
                $type = GFFormsModel::get_input_type($field);
                if ("likert" == $type && rgar($field, "gsurveyLikertEnableScoring")) {
                    $score += $this->get_field_score($field, $entry);
                }
            }
            return $score;
        }

        public function replace_merge_tags($text, $form, $entry, $url_encode, $esc_html, $nl2br, $format) {
            $survey_fields = GFCommon::get_fields_by_type($form, array('survey'));
            if (empty($survey_fields))
                return $text;

            $total_merge_tag = '{survey_total_score}';

            if (false !== strpos($text, $total_merge_tag)) {
                $score_total = $this->get_total_score($form, $entry);
                $text        = str_replace($total_merge_tag, $score_total, $text);
            }

            preg_match_all("/\{score:(.*?)\}/", $text, $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {

                $full_tag       = $match[0];
                $options_string = isset($match[1]) ? $match[1] : "";
                $options        = shortcode_parse_atts($options_string);
                extract(shortcode_atts(array(
                    'id' => 0
                ), $options));
                if (0 == $id)
                    continue;
                $field_id = intval($id);
                $field    = GFFormsModel::get_field($form, $field_id);
                $type     = GFFormsModel::get_input_type($field);
                if ("likert" != $type)
                    continue;
                $score = $this->get_field_score($field, $entry);
                $text  = str_replace($full_tag, $url_encode ? urlencode($score) : $score, $text);
            }

            return $text;
        }

        public function get_total_score($form, $entry) {
            $score         = 0;
            $survey_fields = GFCommon::get_fields_by_type($form, array('survey'));
            if (empty($survey_fields))
                return $score;

            foreach ($form["fields"] as $field) {
                $type = GFFormsModel::get_input_type($field);
                if ("likert" == $type && rgar($field, "gsurveyLikertEnableScoring")) {
                    $score += $this->get_field_score($field, $entry);
                }
            }

            return $score;
        }

        public function get_field_score($field, $entry) {
            $score = 0;
            if (rgar($field, "gsurveyLikertEnableMultipleRows")) {
                // cycle through the entry values in case the the number of choices has changed since the entry was submitted
                foreach ($entry as $key => $value) {
                    if (intval($key) != $field["id"])
                        continue;

                    if (false === strpos($value, ":"))
                        return "";
                    list($row_val, $col_val) = explode(":", $value, 2);

                    foreach ($field["gsurveyLikertRows"] as $row) {
                        if ($row["value"] == $row_val) {
                            foreach ($field["choices"] as $choice) {
                                if ($choice["value"] == $col_val)
                                    $score += floatval(rgar($choice, "score"));
                            }
                        }
                    }
                }
            } else {
                $value = rgar($entry, $field["id"]);
                if (!empty($value)) {
                    foreach ($field["choices"] as $choice) {
                        if ($choice["value"] == $value)
                            $score += floatval(rgar($choice, "score"));
                    }
                }
            }

            return $score;
        }

        public function add_merge_tags($form) {
            $survey_fields = GFCommon::get_fields_by_type($form, array('survey'));
            if (empty($survey_fields))
                return $form;

            $scoring_enabled = false;
            $merge_tags      = array();
            foreach ($form["fields"] as $field) {
                $type = GFFormsModel::get_input_type($field);
                if ("likert" == $type && rgar($field, "gsurveyLikertEnableScoring")) {
                    $scoring_enabled = true;
                    $field_id        = $field["id"];
                    $field_label     = $field['label'];
                    $group           = $field["isRequired"] ? "required" : "optional";
                    $merge_tags[]    = array('group' => $group, 'label' => 'Survey Field Score: ' . $field_label, 'tag' => "{score:id={$field_id}}");
                }
            }
            if ($scoring_enabled) {
                $merge_tags[] = array('group' => 'other', 'label' => 'Survey Total Score', 'tag' => '{survey_total_score}');
            }
            ?>
            <script type="text/javascript">
                if(window.gform)
                    gform.addFilter("gform_merge_tags", "gsurvey_add_merge_tags");
                function gsurvey_add_merge_tags(mergeTags, elementId, hideAllFields, excludeFieldTypes, isPrepop, option) {
                    if(isPrepop)
                        return mergeTags;
                    var customMergeTags = <?php echo json_encode($merge_tags); ?>;
                    jQuery.each(customMergeTags, function (i, customMergeTag) {
                        mergeTags[customMergeTag.group].tags.push({ tag: customMergeTag.tag, label: customMergeTag.label });
                    });

                    return mergeTags;
                }
            </script>
            <?php
            //return the form object from the php hook
            return $form;
        }

        public function import_file_options($options) {
            $options["gsurveyLikertRow"] = array("unserialize_as_array" => true);

            return $options;
        }

        public function get_form_meta($form_id) {
            $form_metas = $this->_form_meta_by_id;

            if (empty($form_metas)) {
                $form_ids = array();
                $forms    = RGFormsModel::get_forms();
                foreach ($forms as $form) {
                    $form_ids[] = $form->id;
                }
                //backwards compatiblity with <1.7
                if (method_exists('GFFormsModel', 'get_form_meta_by_id'))
                    $form_metas = GFFormsModel::get_form_meta_by_id($form_ids);
                else
                    $form_metas = GFFormsModel::get_forms_by_id($form_ids);

                $this->_form_meta_by_id = $form_metas;
            }
            foreach ($form_metas as $form_meta) {
                if ($form_meta["id"] == $form_id)
                    return $form_meta;
            }

        }

        public static function display_export_field_value($value, $form_id, $field_id, $lead){
            $survey = GFSurvey::get_instance();
            return $survey->display_entries_field_value($value, $form_id, $field_id, $lead);
        }

        public function display_entries_field_value($value, $form_id, $field_id, $lead) {

            $form_meta       = RGFormsModel::get_form_meta($form_id);
            $form_meta_field = RGFormsModel::get_field($form_meta, $field_id);

            if (rgar($form_meta_field, "type") != "survey")
                return $value;

            $new_value = $value;
            $sub_type  = rgar($form_meta_field, "inputType");
            switch ($sub_type) {
                case "likert" :
                    if (is_array($value)) {

                        $new_values = array();
                        foreach ($value as $response) {
                            $new_values[] = $this->get_likert_column_text($form_meta_field, $response);
                        }
                        $new_value = implode(', ', $new_values);

                    } else {
                        if (strpos($field_id, ".") !== false) {
                            $value_id = $lead[$field_id];
                            if (false === empty($value_id))
                                $new_value = $this->get_likert_column_text($form_meta_field, $value_id);
                        } else {
                            $values     = explode(", ", $value);
                            $new_values = array();
                            foreach ($values as $response) {
                                $new_values[] = $this->get_likert_column_text($form_meta_field, $response);
                            }
                            $new_value = implode(', ', $new_values);
                        }

                    }
                    break;
                case "rank" :
                    $new_value      = "";
                    $ordered_values = explode(",", $value);

                    if (false === empty($ordered_values)) {
                        $c = 1;
                        foreach ($ordered_values as $ordered_value) {
                            $new_value .= $c++ . ". " . RGFormsModel::get_choice_text($form_meta_field, $ordered_value) . " ";
                        }
                        $new_value = trim($new_value);
                    }

                    break;
                case "rating" :
                    $new_value = GFCommon::selection_display($value, $form_meta_field, $currency = "", $use_text = true);
                    break;
                case "radio" :
                case "checkbox" :
                case "select" :
                    if (isset($form_meta_field["inputs"]) && is_array($form_meta_field["inputs"])) {
                        foreach ($form_meta_field["choices"] as $choice) {
                            $val       = rgar($choice, "value");
                            $text      = RGFormsModel::get_choice_text($form_meta_field, $val);
                            $new_value = str_replace($val, $text, $new_value);
                        }
                    } else {
                        $new_value = RGFormsModel::get_choice_text($form_meta_field, $value);
                    }

                    break;
            }

            return $new_value;
        }

        public function get_likert_column_text($field, $value) {

            if (rgar($field, "gsurveyLikertEnableMultipleRows")) {
                if (false === strpos($value, ":"))
                    return "";
                list($row_val, $col_val) = explode(":", $value, 2);

                foreach ($field["gsurveyLikertRows"] as $row) {
                    if ($row["value"] == $row_val) {
                        foreach ($field["choices"] as $choice) {
                            if ($choice["value"] == $col_val)
                                return $choice["text"];
                        }
                    }
                }
            } else {
                foreach ($field["choices"] as $choice) {
                    if ($choice["value"] == $value)
                        return $choice["text"];
                }
            }


        }

        public function get_likert_row_text($field, $index) {

            return rgar($field, "gsurveyLikertEnableMultipleRows") ? $field["gsurveyLikertRows"][$index]["text"] : "";
        }

        public function display_survey_fields_on_entry_detail($value, $field, $lead, $form) {

            if (rgar($field, "type") !== "survey")
                return $value;
            $new_value  = $value;
            $field_type = GFFormsModel::get_input_type($field);
            switch ($field_type) {
                case "likert" :
                    $new_value = $this->get_survey_field_content("", $field, $value, $lead["id"], $form["id"], $lead);

                    // if original response is not in results display below
                    // TODO - handle orphaned responses (original choice is deleted)
                    break;
                case "rank" :
                    $new_value = $this->get_rank_entry_value_formatted($field, $value);
                    break;
                case "rating" :
                    $new_value = GFCommon::selection_display($value, $field, $currency = "", $use_text = true);
                    break;
                case "radio" :
                case "checkbox" :
                case "select" :
                    if (isset($field["inputs"]) && is_array($field["inputs"])) {
                        foreach ($field["choices"] as $choice) {
                            $val       = rgar($choice, "value");
                            $text      = RGFormsModel::get_choice_text($field, $val);
                            $new_value = str_replace($val, $text, $new_value);
                        }
                    } else {
                        $new_value = RGFormsModel::get_choice_text($field, $value);
                    }

                    break;
            }


            return $new_value;
        }

        public function get_rank_entry_value_formatted($field, $value) {

            $ordered_values = explode(",", $value);
            $new_value      = "<ol class='gsurvey-rank-entry'>";
            foreach ($ordered_values as $ordered_value) {
                $ordered_label = GFCommon::selection_display($ordered_value, $field, $currency = "", $use_text = true);
                $new_value .= sprintf("<li>%s</li>", $ordered_label);
            }
            $new_value .= "</ol>";

            return $new_value;
        }

        // adds gsurvey-field class to likert fields
        public function add_custom_class($classes, $field, $form) {
            if ($field["type"] == "survey")
                $classes .= " gsurvey-survey-field ";

            return $classes;
        }

        public function assign_title($title, $field_type) {
            if ($field_type == "survey")
                $title = __("Survey", "gravityformssurvey");

            return $title;
        }

        public function add_survey_field($field_groups) {

            foreach ($field_groups as &$group) {
                if ($group["name"] == "advanced_fields") {
                    $group["fields"][] = array("class" => "button", "value" => __("Survey", "gravityformssurvey"), "onclick" => "StartAddField('survey');");
                }
            }

            return $field_groups;
        }

        public function add_survey_tooltips($tooltips) {
            $tooltips["gsurvey_question"]                    = "<h6>" . __("Survey Question", "gravityformssurvey") . "</h6>" . __("Enter the question you would like to ask the user.", "gravityformssurvey");
            $tooltips["gsurvey_field_type"]                  = "<h6>" . __("Survey Field Type", "gravityformssurvey") . "</h6>" . __("Select the type of field that will be used for the survey.", "gravityformssurvey");
            $tooltips["gsurvey_likert_columns"]              = "<h6>" . __("Likert Columns", "gravityformssurvey") . "</h6>" . __("Edit the choices for this likert field.", "gravityformssurvey");
            $tooltips["gsurvey_likert_enable_multiple_rows"] = "<h6>" . __("Enable Multiple Rows", "gravityformssurvey") . "</h6>" . __("Select to add multiple rows to the likert field.", "gravityformssurvey");
            $tooltips["gsurvey_likert_rows"]                 = "<h6>" . __("Likert Rows", "gravityformssurvey") . "</h6>" . __("Edit the texts that will appear to the left of each row of choices.", "gravityformssurvey");
            $tooltips["gsurvey_likert_enable_scoring"]       = "<h6>" . __("Enable Scoring", "gravityformssurvey") . "</h6>" . __("Scoring allows different scores for each column. Aggregate scores are displayed in the results page and can be used in merge tags.", "gravityformssurvey");

            return $tooltips;
        }

        public function survey_field_settings($position, $form_id) {
            if ($position == 25) {
                ?>
                <li class="gsurvey-setting-question field_setting">
                    <label for="gsurvey-question">
                        <?php _e("Survey Question", "gravityformssurvey"); ?>
                        <?php gform_tooltip("gsurvey_question"); ?>
                    </label>
                    <textarea id="gsurvey-question" class="fieldwidth-3 fieldheight-2"
                              onkeyup="SetFieldLabel(this.value)"
                              size="35"></textarea>
                </li>
                <li class="gsurvey-setting-field-type field_setting">
                    <label for="gsurvey-field-type">
                        <?php _e("Survey Field Type", "gravityformssurvey"); ?>
                        <?php gform_tooltip("gsurvey_field_type"); ?>
                    </label>
                    <select id="gsurvey-field-type"
                            onchange="if(jQuery(this).val() == '') return; jQuery('#field_settings').slideUp(function(){StartChangeSurveyType(jQuery('#gsurvey-field-type').val());});">
                        <option value="likert"><?php _e("Likert", "gravityformssurvey"); ?></option>
                        <option value="rank"><?php _e("Rank", "gravityformssurvey"); ?></option>
                        <option value="rating"><?php _e("Rating", "gravityformssurvey"); ?></option>
                        <option value="radio"><?php _e("Radio Buttons", "gravityformssurvey"); ?></option>
                        <option value="checkbox"><?php _e("Checkboxes", "gravityformssurvey"); ?></option>
                        <option value="text"><?php _e("Single Line Text", "gravityformssurvey"); ?></option>
                        <option value="textarea"><?php _e("Paragraph Text", "gravityformssurvey"); ?></option>
                        <option value="select"><?php _e("Drop Down", "gravityformssurvey"); ?></option>
                    </select>
                </li>
            <?php
            } elseif ($position == 1362) {
                ?>
                <li class="gsurvey-likert-setting-columns field_setting">

                    <div style="float:right;">
                        <input id="gsurvey-likert-enable-scoring" type="checkbox"
                               onclick="SetFieldProperty('gsurveyLikertEnableScoring', this.checked); jQuery('#gsurvey-likert-columns-container').toggleClass('gsurvey-likert-scoring-enabled');">
                        <label class="inline gfield_value_label" for="gsurvey-likert-enable-scoring">enable scoring</label> <?php gform_tooltip("gsurvey_likert_enable_scoring") ?>
                    </div>
                    <label for="gsurvey-likert-columns">
                        <?php _e("Columns", "gravityformssurvey"); ?>
                        <?php gform_tooltip("gsurvey_likert_columns"); ?>
                    </label>

                    <div id="gsurvey-likert-columns-container">
                        <ul id="gsurvey-likert-columns">
                        </ul>
                    </div>
                </li>
                <li class="gsurvey-likert-setting-enable-multiple-rows field_setting">
                    <input type="checkbox" id="gsurvey-likert-enable-multiple-rows"
                           onclick="field = GetSelectedField(); var value = jQuery(this).is(':checked'); SetFieldProperty('gsurveyLikertEnableMultipleRows', value); gsurveyLikertUpdateInputs(field); gsurveyLikertUpdatePreview(); jQuery('.gsurvey-likert-setting-rows').toggle('slow');"/>
                    <label for="gsurvey-likert-enable-multiple-rows" class="inline">
                        <?php _e('Enable multiple rows', "gravityformssurvey"); ?>
                        <?php gform_tooltip("gsurvey_likert_enable_multiple_rows") ?>
                    </label>

                </li>
                <li class="gsurvey-likert-setting-rows field_setting">
                    <?php _e("Rows", "gravityformssurvey"); ?>
                    <?php gform_tooltip("gsurvey_likert_rows") ?>
                    <div id="gsurvey-likert-rows-container">
                        <ul id="gsurvey-likert-rows"></ul>
                    </div>
                </li>
            <?php
            }
        }


    } // end class

    GFSurvey::get_instance();
}
