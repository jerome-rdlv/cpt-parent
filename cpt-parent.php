<?php

CptParent::init();

class CptParent
{
    const TEXTDOMAIN = 'cpt-parent';
    const OPTION_FORMAT = 'page_for_%s';

    private static $instances = array();
    private static $post_type_args = array();

    public static function init()
    {
        $i18n_path = dirname(plugin_basename(__FILE__)) . '/lang';
        if (strpos(__DIR__, WPMU_PLUGIN_DIR) !== false) {
            load_muplugin_textdomain(self::TEXTDOMAIN, $i18n_path);
        } else {
            load_plugin_textdomain(self::TEXTDOMAIN, false, $i18n_path);
        }

        // save original post type args and update it
        add_filter('register_post_type_args', function ($args, $post_type) {
            if (apply_filters('cpt_has_parent_page', false, $post_type)) {
                self::$post_type_args[$post_type] = $args;
                $parent = get_option(sprintf(self::OPTION_FORMAT, $post_type));
                if ($parent) {
                    $args['rewrite']['slug'] = get_page_uri($parent);
                    $args['rewrite']['parent'] = $parent;
                }
            }
            return $args;
        }, 10, 2);

        add_action('registered_post_type', function ($post_type, WP_Post_Type $post_type_object) {
            if (apply_filters('cpt_has_parent_page', false, $post_type)) {
                if (!array_key_exists($post_type, self::$instances)) {
                    self::$instances[$post_type] = new CptParent($post_type, $post_type_object);
                }
            }
        }, 10, 2);

        add_action('cpt_parent_page_context', function () {
            $post_type = get_post_type();
            if (is_post_type_archive() && apply_filters('cpt_has_parent_page', false, $post_type)) {
                $pagename = get_page_uri(get_option(sprintf(self::OPTION_FORMAT, $post_type)));
                query_posts(array(
                    'pagename' => $pagename,
                ));
                the_post();
            }
        });

        add_action('cpt_parent_reset_context', function () {
            wp_reset_query();
        });

        // transform breadcrumbs
        add_filter('wpseo_breadcrumb_links', function ($crumbs) {
            foreach ($crumbs as $index => &$crumb) {
                if (!array_key_exists('ptarchive', $crumb)) {
                    continue;
                }
                $post_type = $crumb['ptarchive'];
                if (!array_key_exists($post_type, self::$instances)) {
                    continue;
                }
                $post_type_obj = get_post_type_object($post_type);
                if (empty($post_type_obj->rewrite['parent'])) {
                    continue;
                }
                $parent = $post_type_obj->rewrite['parent'];
                $parent_post = get_post($parent);
                unset($crumb['ptarchive']);
                $crumb['id'] = $parent_post->ID;

                // add ancestors
                while ($parent_post->post_parent) {
                    $parent_post = get_post($parent_post->post_parent);
                    array_splice($crumbs, $index, 0, array(array(
                        'id' => $parent_post->ID,
                    )));
                }

                break;
            }
            return $crumbs;
        }, 10);
    }

    private $post_type;
    private $post_type_object;
    private $option_name;
    private $parent = null;

    private function __construct($post_type, WP_Post_Type $post_type_object)
    {
        $this->post_type = $post_type;
        $this->post_type_object = $post_type_object;
        $this->option_name = sprintf(self::OPTION_FORMAT, $post_type);
        if (!empty($post_type_object->rewrite['parent'])) {
            $this->parent = $post_type_object->rewrite['parent'];
        }

        // display parent page field on reading settings page
        add_action('admin_init', function () {
            register_setting('reading', $this->option_name, array(
                'type' => 'integer',
            ));
            add_settings_field(
                $this->option_name,
                '<label for="' . $this->option_name . '">' . sprintf(__('Parent page for %s type', self::TEXTDOMAIN), $this->post_type_object->label) . '</label>',
                function () {
                    echo wp_dropdown_pages(array(
                        'name'             => $this->option_name,
                        'id'               => $this->option_name,
                        'echo'             => 0,
                        'show_option_none' => __('— Select —', self::TEXTDOMAIN),
                        'selected'         => get_option($this->option_name),
                    ));
                },
                'reading'
            );
        });

        // update post type and flush rewrite rules when parent changes
        add_action('update_option_' . $this->option_name, function ($old_value, $value) {
            $this->parent = $value;
            $this->rewriteRules();
        }, 10, 2);


        if ($this->parent) {

            // add page edit link in CPT menu
            add_action('admin_menu', function () {
                global $submenu;
                $slug = 'edit.php?post_type=' . $this->post_type;
                if (isset($submenu[$slug])) {
                    $submenu[$slug][] = array(
                        __('Archive page', self::TEXTDOMAIN),
                        'edit_posts',
                        get_admin_url(null, sprintf('post.php?post=%s&action=edit', $this->parent)),
                    );
                }
            });

            // display notice on edit screen
            add_action('edit_form_after_title', function ($post) {
                $post_ids = $this->getParentIds();
                if (in_array($post->ID, $post_ids)) {
                    echo '<div class="notice notice-warning inline"><p>' .
                        sprintf(__('You are currently editing the page that shows your latest %s.', self::TEXTDOMAIN), strtolower($this->post_type_object->label)) .
                        '</p></div>';
                }
            });

            // flush rewrite rule on parent page slug changes
            add_action('save_post', function ($post_id) {
                $post_ids = $this->getParentIds();
                if (in_array($post_id, $post_ids)) {
                    $old = get_post();
                    $new = get_post($post_id);
                    if ($old->post_name != $new->post_name) {
                        $this->rewriteRules();
                    }
                }
            });

            // add ancestor classes, lost because archive entry is also a page
            add_filter('nav_menu_css_class', function ($classes, $item) {
                // if item is post type parent page or post type archive item
                if (
                    ($item->type === 'post_type_archive' && $item->object === $this->post_type) ||
                    ($item->object_id === $this->parent)
                ) {
                    if (is_single()) {
                        $post = get_post();
                        if ($post && $post->post_type === $this->post_type) {
                            // menu item is ancestor of current post is of type $this->post_type
                            $classes[] = 'current_page_ancestor';
                            $classes[] = 'current-page-ancestor';
                            $classes[] = 'current-menu-ancestor';
                        }
                    }
                    elseif (get_the_ID() == get_option($this->option_name) || is_post_type_archive($this->post_type)) {
                        // we are on post type parent page / on post type archive page
                        $classes[] = 'current-menu-item';
                        $classes[] = 'current_page_item';
                    }
                }
                return $classes;
            }, 10, 2);

            // add ACF page type
            add_filter('acf/location/rule_values/page_type', function ($values) {
                $values[$this->post_type] = sprintf(__('Page of %s', self::TEXTDOMAIN), strtolower($this->post_type_object->label));
                return $values;
            });
            add_filter('acf/location/rule_match/page_type', function ($result, $rule, $screen) {
                $post_ids = $this->getParentIds();
                if ($rule['value'] === $this->post_type && !empty($screen['post_id'])) {
                    if ($rule['operator'] === '==' && in_array($screen['post_id'], $post_ids)) {
                        return true;
                    }
                    if ($rule['operator'] === '!=' && !in_array($screen['post_id'], $post_ids)) {
                        return true;
                    }
                    return false;
                }
                return $result;
            }, 10, 3);

            // translate rewrite rules
            /*
             * Should let translation plugin handle that.
             * But keep the logic here, it works
             * 
            if (function_exists('pll_languages_list')) {
                add_filter('rewrite_rules_array', function ($rules) {
                    $languages = pll_languages_list();
                    if (count($languages) < 2) {
                        return $rules;
                    }
                    
                    $baseSlug = $this->post_type_object->rewrite['slug'];

                    $slugs = [$baseSlug];
                    foreach ($languages as $lang) {
                        $parent = pll_get_post($this->parent, $lang);
                        $slug = get_page_uri($parent);
                        if ($slug !== $baseSlug) {
                            $slugs[] = $slug;
                        }
                    }

                    $newRules = [];
                    foreach ($rules as $key => $rule) {
                        // if rule contains base slug
                        if (strpos($key, $baseSlug) !== false) {
                            // we add slug language variations
                            $newRules[str_replace(
                                $baseSlug,
                                '(?:'. implode('|', $slugs) .')',
                                $key
                            )] = $rule;
                        }
                        else {
                            // keep original rule
                            $newRules[$key] = $rule;
                        }
                    }
                    return $newRules;
                });
            }
            */
        }
    }

    private function getParentIds()
    {
        if (function_exists('pll_get_post_translations')) {
            $post_ids = pll_get_post_translations($this->parent);
        } else {
            $post_ids = [$this->parent];
        }
        return $post_ids;
    }

    private function rewriteRules()
    {
        // force post type update before flush rewrite rules
        register_post_type($this->post_type, self::$post_type_args[$this->post_type]);
        flush_rewrite_rules();
    }
}