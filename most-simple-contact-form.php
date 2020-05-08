<?php
/*
Plugin Name:  Most simple contact form
Plugin URI:   https://hansspiess.de
Description:  Generates a simple contact form via shortcode.
Version:      1.0.0
Author:       Hans Spieß
Author URI:   https://hansspiess.de
License:      GPL2
License URI:  https://www.gnu.org/licenses/gpl-2.0.html
Text Domain:  most-simple-contact-form
Domain Path:  /languages
*/

if ( !defined( 'MSCF_VERSION' ) ) {
    define( MSCF__VERSION, '1.0.0' );
}

if ( !defined( 'MSCF__NAMESPACE' ) ) {
    define( MSCF__NAMESPACE, 'most-simple-contact-form' );
}

if ( !class_exists( 'Most_Simple_Contact_Form' ) ) {
    class Most_Simple_Contact_Form {

        /**
         * Static property to hold our singleton instance
         *
         */
        static $instance = false;

        /**
         * Allowed attributes for shortcode
         */
        const SHORTCODE_ATTS = [
            'mailto' => false, 
            'headermailto' => false, 
            'headername' => false,
            'cssbutton' => 'btn btn-primary',
            'cssalert' => 'alert alert-'
        ];

        /**
         * This is our constructor
         *
         * @return void
         */
        private function __construct() {
            // Load plugin textdomain
            add_action( 'plugins_loaded', array( $this, 'textdomain' ) );

            // Register [most-simple-contact-form] shortcode
            add_shortcode( MSCF__NAMESPACE, array( $this, 'display_form' ) );

            // Capture form post for both loggedin and loggedout users
            add_action( 'admin_post_nopriv_' . MSCF__NAMESPACE, array( $this, 'process_form' ) );
            add_action( 'admin_post_' . MSCF__NAMESPACE, array( $this, 'process_form' ) );
       }

        /**
         * If an instance exists, this returns it.  If not, it creates one and returns it.
         *
         * @return Most_Simple_Contact_Form
         */
        public static function getInstance() {
            if ( !self::$instance ) {
                self::$instance = new self;
            }
            return self::$instance;
        }

        /**
         * Load textdomain
         *
         * @return void
         */
        public function textdomain() {
            load_plugin_textdomain( MSCF__NAMESPACE, false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
        }

        /**
         * Process contact form
         *
         * @return void
         */
        public function process_form() {

            // Return if nonce is invalid
            if ( ! check_admin_referer( MSCF__NAMESPACE, MSCF__NAMESPACE ) ) {
                return;
            }

            // Safely get post vars
            $name       = $this->_get_field_value( MSCF__NAMESPACE . '_name' );
            $email      = $this->_get_field_value( MSCF__NAMESPACE . '_url' );
            $message    = $this->_get_field_value( MSCF__NAMESPACE . '_message' );
            $honeypot   = $this->_get_field_value( MSCF__NAMESPACE . '_email' );
            $before     = $this->_get_field_value( MSCF__NAMESPACE . '_before' );
            $tooEarly   = $before !== '' ? $this->_compare_times( $before ) : true;

            // Get transient and apply saved shortcode atts ($mailto, $headermailto, $headername)
            $transient_atts = get_transient( MSCF__NAMESPACE . '_' . md5( home_url() . wp_get_referer() ) );
            foreach (self::SHORTCODE_ATTS as $attr => $value) {
                if ($transient_atts[$attr]) {
                    ${$attr} = $transient_atts[$attr];
                }
            }

            // Apply fallbacks for wp_mail headers
            $mailto     = $mailto ? $mailto : get_bloginfo( 'admin_email' );
            $headermailto = $headermailto ? $headermailto : get_bloginfo( 'admin_email' );
            $headername = $headername ? $headername : get_bloginfo( 'name' );

            // Variables for wp_mail() - have to be double quotes...
            $subject    = "Message from " . $headername;
            $body       = "Name: " . strip_tags( $name ) . "\r\n" .
                          "Email: " . $email . "\r\n" .
                          "Message: " . strip_tags( $message );
            $headers    = "From: " . $headername . " <" . $headermailto . ">\r\n Reply-To: " . $headername . " <" . $headermailto . ">\r\n";

            // Validation and processing
            if ( $honeypot != '' || $tooEarly === true ) {
                if ( !$name || !$email || !$message ) {
                    $status['warning'][] = 'missing_fields';
                } else {
                    $status['danger'][] = 'no_script_allowed';
                }
            } else {
                if ( !$name || !$email || !$message ) {
                    $status['warning'][] = 'missing_fields';
                }
                if ( !is_email( $email ) &&  $email ) {
                    $status['warning'][] = 'email_invalid';
                }
                if ( empty( $status ) ) {
                    if ( wp_mail( [$mailto], $subject, $body, $headers ) ) {
                        $status['success'][] = 'success';
                        unset( $name, $email, $message );
                    } else {
                        $status['danger'][] = 'error';
                    }
                }
            }

            // Save fields and status in transient to be displayed in form after redirect
            set_transient( 
                MSCF__NAMESPACE . '_' . wp_get_session_token(),
                [
                    'fields' => [
                        'name' => $name,
                        'email' => $email,
                        'message' => $message
                    ],
                    'status' => $status
                ],
                100 // transient will be deleted on next page, however to "auto clean" this is set
            );

            // Redirect to form url
            if ( wp_get_referer() ) {
                wp_safe_redirect( wp_get_referer() );
                exit;
            } else {
                wp_safe_redirect( get_home_url() );
                exit;
            }

        }

        /**
         * Display contact form
         *
         * @return html
         */
        public function display_form( $atts = [], $content = null, $tag = '' ) {
            
            // Save atts from shortcode in transient to be used when processing form
            $atts = $this->_set_transient_atts( $atts, $tag );

            // Read transient that was saved when processing a form
            $saved = $this->_get_delete_transient_status();
            $fields = $saved['fields'];
            $status = $saved['status'];

            ob_start();

            echo $this->_get_messages_html( $status, $atts );
            ?>
                <form role="form" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
                    <input type="hidden" name="action" value="<?php echo MSCF__NAMESPACE; ?>">
                    <input type="hidden" name="<?php echo MSCF__NAMESPACE; ?>_before" value="<?php echo $this->_simple_crypt( current_time( 'timestamp' ), 'e' ); ?>">
                    <?php wp_nonce_field( MSCF__NAMESPACE, MSCF__NAMESPACE ); ?>
                    <div class="form-group">
                        <label for="<?php echo MSCF__NAMESPACE; ?>_name"><?php _e( 'Name', MSCF__NAMESPACE); ?></label>
                        <input 
                            type="text" 
                            id="<?php echo MSCF__NAMESPACE; ?>_name" name="<?php echo MSCF__NAMESPACE; ?>_name" 
                            value="<?php echo esc_html($fields['name']); ?>" 
                            class="form-control"
                            placeholder="<?php _e( 'Your name', MSCF__NAMESPACE); ?>">
                    </div>
                    <div class="form-group hide">
                        <label for="<?php echo MSCF__NAMESPACE; ?>_email"><?php _e( 'Homepage', MSCF__NAMESPACE); ?></label>
                        <input 
                            type="text" 
                            id="<?php echo MSCF__NAMESPACE; ?>_email" name="<?php echo MSCF__NAMESPACE; ?>_email" 
                            value="" 
                            class="form-control" 
                            placeholder="<?php _e( 'Your homepage', MSCF__NAMESPACE); ?>">
                    </div>
                    <div class="form-group">
                        <label for="<?php echo MSCF__NAMESPACE; ?>_url"><?php _e( 'Email address', MSCF__NAMESPACE); ?></label>
                        <input 
                            type="text" 
                            id="<?php echo MSCF__NAMESPACE; ?>_url" name="<?php echo MSCF__NAMESPACE; ?>_url" 
                            value="<?php echo esc_html($fields['email']); ?>" 
                            class="form-control" 
                            placeholder="<?php _e( 'Your email address', MSCF__NAMESPACE); ?>">
                    </div>
                    <div class="form-group">
                        <label for="<?php echo MSCF__NAMESPACE; ?>_message"><?php _e( 'Message', MSCF__NAMESPACE); ?></label>
                        <textarea 
                            rows="4" 
                            id="<?php echo MSCF__NAMESPACE; ?>_message" name="<?php echo MSCF__NAMESPACE; ?>_message" 
                            class="form-control" 
                            placeholder="<?php _e( 'Your message', MSCF__NAMESPACE); ?>"><?php echo esc_textarea($fields['message']); ?></textarea>
                    </div>
                    <div class="form-group">
                        <small class="form-small">
                            <?php
                                $url = home_url();
                                $link = sprintf( wp_kses( __( 'By submitting the form, you consent to the processing of your data in order to process the request. Further information in the <a href="%s/datenschutzerklaerung/">privacy policy</a>.', MSCF__NAMESPACE ), array(  'a' => array( 'href' => array() ) ) ), esc_url( $url ) );
                                echo $link;
                            ?>
                        </small>
                    </div>
                    <button type="submit" class="<?php echo $atts['cssbutton']; ?>"><?php _e( 'Send message', MSCF__NAMESPACE); ?></button>
                </form>

            <?php

            return ob_get_clean();
        }

        /**
         * Save additional attributes from shortcode in transient for later usage
         */
        private function _set_transient_atts ( $atts, $tag ) {
            // Normalize attribute keys, lowercase
            $atts = array_change_key_case( (array)$atts, CASE_LOWER );

            // Merge default attributes with user attributes
            $fields = shortcode_atts( self::SHORTCODE_ATTS, $atts, $tag );

            // Current url used as id for transient
            $url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER[HTTP_HOST] . $_SERVER[REQUEST_URI];

            // Save atts of current page in transient
            set_transient( 
                MSCF__NAMESPACE . '_' . md5( $url ), 
                $fields,
                100
            );

            return $fields;
        }

        /**
         * Retrieve and then delete transient that carries status and fields
         */
        private function _get_delete_transient_status () {
            $id = MSCF__NAMESPACE . '_' . wp_get_session_token();
            $transient = get_transient( $id );
            delete_transient( $id );
            return $transient;
        }

        private function _get_messages_html ( $messages, $atts ) {
            if (!count($messages)) {
                return;
            }
            $trans = [
                'missing_fields' => __( 'Please fill out all fields.', MSCF__NAMESPACE ),
                'email_invalid' => __( 'Please check your email address.', MSCF__NAMESPACE ),
                'no_script_allowed' => __( 'Please dont call this by script.', MSCF__NAMESPACE ),
                'error' => __( 'The message could not be sent.', MSCF__NAMESPACE ),
                'success' => __( 'Thanks! The message has been sended.', MSCF__NAMESPACE )
            ];
            foreach ($messages as $type => $type_messages) {
                $m = implode( '<br>', $type_messages );
                $o .= '<div class="' . $atts['cssalert'] . $type .'">' . $trans[$m]  . '</div>';
            }
            return $o;
        }

        /**
         * Savely get post values
         */
        private function _get_field_value( $field ){
            return sanitize_text_field( isset( $_POST[$field] ) ? $_POST[$field] : '' );
        }

        /**
         * Compares timestamps
         */
        private function _compare_times( $before_encrypted_timestamp, $range = 4 ) {
            $before = $this->_simple_crypt( $before_encrypted_timestamp, 'd' );
            $now = current_time( 'timestamp' );
            return $now < $before + $range;
        }

        /**
         * Encrypt and decrypt
         * 
         * @author Nazmul Ahsan <n.mukto@gmail.com>
         * @link http://nazmulahsan.me/simple-two-way-function-encrypt-decrypt-string/
         *
         * @param string $string string to be encrypted/decrypted
         * @param string $action what to do with this? e for encrypt, d for decrypt
         */
        private function _simple_crypt( $string, $action = 'e' ) {
            // you may change these values to your own
            $secret_key = 'my_simple_secret_key';
            $secret_iv = 'my_simple_secret_iv';

            $output = false;
            $encrypt_method = "AES-256-CBC";
            $key = hash( 'sha256', $secret_key );
            $iv = substr( hash( 'sha256', $secret_iv ), 0, 16 );

            if( $action == 'e' ) {
                $output = base64_encode( openssl_encrypt( $string, $encrypt_method, $key, 0, $iv ) );
            }
            else if( $action == 'd' ){
                $output = openssl_decrypt( base64_decode( $string ), $encrypt_method, $key, 0, $iv );
            }

            return $output;
        }

    }
 
}

// Instantiate our class
$Most_Simple_Contact_Form = Most_Simple_Contact_Form::getInstance();
