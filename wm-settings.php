<?php
/*
URI: http://webmaestro.fr/wordpress-settings-api-options-pages/
Author: Etienne Baudry
Author URI: http://webmaestro.fr
Description: Simplified options system for WordPress.
Version: 1.2.3-b
License: GNU General Public License
GitHub URI: https://github.com/dmh-kevin/wm-settings
Forked From: https://github.com/WebMaestroFr/wm-settings
GitHub Branch: master
*/
if ( ! class_exists( 'WM_Settings' ) ) {

  class WM_Settings {

    private $page,
    $title,
    $menu,
    $settings = array(),
    $empty = true;

    public function __construct( $page = 'custom_settings', $title = null, $menu = array(), $settings = array(), $args = array() ) {
      $this->page  = $page;
      $this->title = $title ? $title : 'Custom Settings';
      $this->menu  = is_array( $menu ) ? array_merge(
        array(
          'parent'     => 'themes.php',
          'title'      => $this->title,
          'capability' => 'manage_options',
          'icon_url'   => null,
          'position'   => null,
        ),
        $menu
      ) : false;
      $this->apply_settings( $settings );
      $this->args = array_merge(
        array(
          'submit' => 'Save Settings',
          'reset'  => 'Reset Settings',
        ),
        $args 
      );
      add_action( 'admin_menu', array( $this, 'admin_menu' ) );
      add_action( 'admin_init', array( $this, 'admin_init' ) );
    }

    public function apply_settings( $settings ) {
      foreach ( $settings as $setting => $section ) {
        $section = array_merge(
          array(
            'title'       => null,
            'description' => null,
            'fields'      => array(),
          ), 
          $section 
        );
        foreach ( $section['fields'] as $name => $field ) {
          $field = array_merge(
            array(
              'type'        => 'text',
              'label'       => null,
              'description' => null,
              'default'     => null,
              'sanitize'    => null,
              'options'     => null,
              'action'      => null,
            ),
            $field
          );
          if ( $field['type'] === 'action' && is_callable( $field['action'] ) ) {
            add_action( "wp_ajax_{$setting}_{$name}", $field['action'] );
          }
          $section['fields'][$name] = $field;
        }
        $this->settings[$setting] = $section;
        if ( ! get_option( $setting ) ) {
          add_option( $setting, $this->get_defaults( $setting ) );
        }
      }
    }

    private function get_defaults( $setting ) {
      $defaults = array();
      foreach ( $this->settings[$setting]['fields'] as $name => $field ) {
        if ( $field['default'] !== null ) {
          $defaults[$name] = $field['default'];
        }
      }
      return $defaults;
    }

    public function admin_menu() {
      if ( $this->menu ) {
        if ( $this->menu['parent'] ) {
          $page = add_submenu_page( $this->menu['parent'], $this->title, $this->menu['title'], $this->menu['capability'], $this->page, array( $this, 'do_page' ) );
        } else {
          $page = add_menu_page( $this->title, $this->menu['title'], $this->menu['capability'], $this->page, array( $this, 'do_page' ), $this->menu['icon_url'], $this->menu['position'] );
          if ( $this->title !== $this->menu['title'] ) {
            add_submenu_page( $this->page, $this->title, $this->title, $this->menu['capability'], $this->page );
          }
        }
        add_action( 'load-' . $page, array( $this, 'load_page' ) );
      }
    }

    public function admin_init() {
      foreach ( $this->settings as $setting => $section ) {
        register_setting( $this->page, $setting, array( $this, 'sanitize_setting' ) );
        add_settings_section( $setting, $section['title'], array( $this, 'do_section' ), $this->page );
        if ( ! empty( $section['fields'] ) ) {
          $this->empty = false;
          $values = self::get_setting( $setting );
          foreach ( $section['fields'] as $name => $field ) {
            $id    = $setting . '_' . $name;
            $field = array_merge(
              array(
                'id'        => $id,
                'name'      => $setting . '[' . $name . ']',
                'value'     => isset( $values[$name] ) ? $values[$name] : null,
                'label_for' => $id,
              ),
              $field
            );
            add_settings_field( $name, $field['label'], array( __CLASS__, 'do_field' ), $this->page, $setting, $field );
          }
        }
      }
      if ( isset( $_POST["{$this->page}_reset"] ) ) {
        $this->reset();
      }
    }

    public function load_page() {
      if ( isset( $_GET['settings-updated'] ) && $_GET['settings-updated'] ) {
        do_action( "{$this->page}_settings_updated" );
      }
      add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_enqueue_scripts' ) );
    }

    public static function admin_enqueue_scripts() {
      wp_enqueue_media();
      wp_enqueue_script( 'wm-settings', plugin_dir_url( __FILE__ ) . 'wm-settings.js', array( 'jquery' ) );
      wp_localize_script(
        'wm-settings',
        'ajax',
        array(
          'url' => admin_url( 'admin-ajax.php' ),
          'spinner' => admin_url( 'images/spinner.gif' ),
        )
      );
      wp_enqueue_style( 'wm-settings', plugin_dir_url( __FILE__ ) . 'wm-settings.css' );
    }

    private function reset() {
      foreach ( $this->settings as $setting => $section ) {
        $_POST[$setting] = array_merge( $_POST[$setting], $this->get_defaults( $setting ) );
      }
      add_settings_error( $this->page, 'settings_reset', 'Default settings have been reset.' );
    }

    public function do_page() { ?>
    <form action="options.php" method="POST" enctype="multipart/form-data" class="wrap">
      <h2><?php echo esc_html( $this->title ); ?></h2>
      <?php
      // Avoid showing admin notice twice
      // TODO : Target the pages where it happens
      if ( ! in_array( $this->menu['parent'], array( 'options-general.php' ) ) ) {
        settings_errors();
      }
      do_settings_sections( $this->page );
      if ( ! $this->empty ) {
        settings_fields( $this->page );
        submit_button( $this->args['submit'], 'large primary' );
        if ( $this->args['reset'] ) {
          submit_button( $this->args['reset'], 'small', $this->page . '_reset', true, array( 'onclick' => "return confirm('" . 'Do you really want to reset all these settings to their default values ?' . ')' ) );
        }
      }
?>
    </form>
  <?php }

    public function do_section( $args ) {
      $id = isset( $args['id'] ) ? $args['id'] : '';

      if ( $text = $this->settings[$id]['description'] ) {
        echo esc_html( $text );
      }
      echo '<input name="' . esc_html( $id . '[' . $this->page . '_setting]' ) .'" type="hidden" value="' . esc_attr( $id ) . '" />';
    }

    public static function do_field( $args ) {
      $name        = isset( $args['name'] ) ? $args['name'] : '';
      $type        = isset( $args['type'] ) ? $args['type'] : '';
      $value       = isset( $args['value'] ) ? $args['value'] : '';
      $options     = isset( $args['options'] ) ? $args['options'] : false;
      $id          = isset( $args['id'] ) ? $args['id'] : '';
      $label       = isset( $args['label'] ) ? $args['label'] : '';
      $action      = isset( $args['action'] ) ? $args['action'] : false;
      $description = isset( $args['description'] ) ? $args['description'] : false;

      switch ( $args['type'] ) {
      case 'checkbox':
        $check = checked( 1, $value, false );
        echo '<label><input name="' . esc_attr( $name ) . '" id="' . esc_attr( $id ) . '" type="checkbox" value="1" ' . esc_attr( $check ) . '/>';
        if ( false !== $description ) {
          echo '<p class="description">' . esc_html( $description ) . '</p>';
        }
        echo '</label>';
        break;

      case 'radio':
        if ( ! $options ) {
          'No options defined.';
        }
        echo '<fieldset id="' . esc_attr( $id ) . '">';
        $first_key = key( $options );
        foreach ( $options as $v => $label ) {
          if ( $v !== $first_key ) echo '<br />';
          echo '<label><input name="' . esc_attr( $name ) . '" type="radio" value="' . esc_attr( $v ) . '" ' . checked( esc_attr( $v ), esc_attr( $value ), false ) . ' /> ' . esc_html( $label ) . '</label>';
        }
        if ( false !== $description ) :
          echo '<p class="description">' . esc_html( $description ) . '</p>';
        endif;
        echo '</fieldset>';
        break;

      case 'select':
        if ( ! $options ) {
          'No options defined.';
        }
        echo '<select name="' . esc_attr( $name ) . '" id="' . esc_attr( $id ) . '>"';
        foreach ( $options as $v => $label ) {
          echo '<option value="' . esc_attr( $v ) . '" ' . selected( esc_attr( $v ), esc_attr( $value ), false ) . ' />' . esc_html( $label ) . '</option>';
        }
        echo '</select>';
        if ( false !== $description ):
          echo '<p class="description">' . esc_html( $description ) . '</p>';
        endif;
        break;

      case 'media':
        echo '<fieldset class="wm-settings-media" id="' . esc_attr( $id ) . '"><input name="' . esc_attr( $name ) . '" type="hidden" value="' . esc_attr( $value ) . '" />';
        if ( $value ) {
          echo wp_get_attachment_image( $value, 'medium' );
        }
        echo '<p><a class="button button-large wm-select-media" title="' . esc_attr( $label ) . '">Select  ' . esc_html( $label )  . '</a>';
        echo '<a class="button button-small wm-remove-media" title="' . esc_attr( $label ) . '">Remove ' . esc_html( $label ) . '</a></p>';
        if ( false !== $description ) :
          echo '<p class="description">' . esc_html( $description ) . '</p>';
        endif;
        echo '</fieldset>';
        break;

      case 'textarea':
        echo '<textarea name="' . esc_attr( $name ) . '" rows="5" id="' . esc_attr( $id ) . '" class="large-text">' . esc_attr( $value ) . '</textarea>';
        if ( false !== $description ):
          echo '<p class="description">' . esc_html( $description ) . '</p>';
        endif;
        break;

      case 'multi':
        if ( ! $options ) {
          'No options defined.';
        }
        echo '<fieldset id="' . esc_attr( $id ) . '">';
        $first_key = key( $options );
        foreach ( $options as $n => $label ) {
          if ( $n !== $first_key ) echo '<br />';
          echo '<label><input name="' . esc_attr( $name ) . '" type="checkbox" value="1" ' . checked( 1, esc_attr( $value[$n] ), false ) . ' /> ' . esc_html( $label ) . '</label>';
        }
        if ( false !== $description ) :
          echo '<p class="description">' . esc_html( $description ) . '</p>';
        endif;
        echo '</fieldset>';
        break;

      case 'action':
        if ( ! $action ) {
          'No action defined.';
        }
        echo '<p class="wm-settings-action"><input name="' . esc_attr( $name ) . '" id="' . esc_attr( $id ) . '" type="button" class="button button-large" value="' . esc_attr( $label ) . '" /></p>';
        if ( false !== $description ):
          echo '<p class="description">' . esc_html( $description ) . '</p>';
        endif;
        break;

      case 'divider':
        echo '<hr>';
        break;

      default:
        echo '<input name="' . esc_attr( $name ) . '"  id="' . esc_attr( $id ) . '" type="' . esc_attr( $type ) . '" value="' . esc_attr( $value ) . '" class="regular-text" />';
        if ( false !== $description ) :
          echo '<p class="description">' . esc_html( $description ) . '</p>';
        endif;
        break;
      }
    }

    public function sanitize_setting( $inputs ) {
      $values = array();
      if ( ! empty( $inputs["{$this->page}_setting"] ) ) {
        $setting = $inputs["{$this->page}_setting"];
        foreach ( $this->settings[$setting]['fields'] as $name => $field ) {
          $input = array_key_exists( $name, $inputs ) ? $inputs[$name] : null;
          if ( $field['sanitize'] ) {
            $values[$name] = call_user_func( $field['sanitize'], $input, $name );
          } else {
            switch ( $field['type'] ) {
            case 'checkbox':
              $values[$name] = $input ? 1 : 0;
              break;

            case 'radio':
            case 'select':
              $values[$name] = sanitize_key( $input );
              break;

            case 'media':
              $values[$name] = absint( $input );
              break;

            case 'textarea':
              $text  = '';
              $nl    = 'WM-SETTINGS-NEW-LINE';
              $tb    = 'WM-SETTINGS-TABULATION';
              $lines = explode( $nl, sanitize_text_field( str_replace( "\t", $tb, str_replace( "\n", $nl, $input ) ) ) );
              foreach ( $lines as $line ) {
                $text .= str_replace( $tb, "\t", trim( $line ) ) . "\n";
              }
              $values[$name] = trim( $text );
              break;

            case 'multi':
              if ( ! $input || empty( $field['options'] ) ) {
                break;
              }
              foreach ( $field['options'] as $n => $opt ) {
                $input[$n] = empty( $input[$n] ) ? 0 : 1;
              }
              $values[$name] = json_encode( $input );
              break;

            case 'action':
              break;

            case 'email':
              $values[$name] = sanitize_email( $input );
              break;

            case 'url':
              $values[$name] = esc_url_raw( $input );
              break;

            case 'number':
              $values[$name] = floatval( $input );
              break;

            default:
              $values[$name] = sanitize_text_field( $input );
              break;
            }
          }
        }
        return $values;
      }
      return $inputs;
    }

    public static function get_setting( $setting, $option = false ) {
      $setting = get_option( $setting );
      if ( is_array( $setting ) ) {
        if ( $option ) {
          return isset( $setting[$option] ) ? self::parse_multi( $setting[$option] ) : false;
        }
        foreach ( $setting as $k => $v ) {
          $setting[$k] = self::parse_multi( $v );
        }
        return $setting;
      }
      return $option ? false : $setting;
    }

    private static function parse_multi( $result ) {
      // Check if the result was recorded as JSON, and if so, returns an array instead
      return ( is_string( $result ) && $array = json_decode( $result, true ) ) ? $array : $result;
    }
  }

  function wm_get_setting( $setting, $option = false ) {
    return WM_Settings::get_setting( $setting, $option );
  }

  function wm_create_settings_page( $page = 'custom_settings', $title = null, $menu = array(), $settings = array(), $args = array() ) {
    return new WM_Settings( $page, $title, $menu, $settings, $args );
  }
}
