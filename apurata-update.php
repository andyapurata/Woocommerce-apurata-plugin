<?php
function console_log( $data ){
    echo '<script>';
    echo 'console.log('. json_encode( $data ) .')';
    echo '</script>';
}
class Apurata_Update {

    private $file;

    private $plugin;

    private $basename;

    private $active;

    private $username;

    private $repository;

    private $authorize_token;

    private $github_response;

    public function __construct($file) {
        $this->file = $file;
        add_action('admin_init', array($this, 'set_plugin_properties'));
        return $this;
    }

    public function initialize() {
        add_filter('pre_set_site_transient_update_plugins', array($this,'modify_transient'), 10, 1 );
        add_filter('plugins_api', array( $this, 'plugin_popup'), 10, 3);
        //add_filter('upgrader_post_install', array($this, 'after_install'), 10, 3 );  
    }

    public function set_plugin_properties() {
        $this->plugin = get_plugin_data($this->file);
        $this->basename = plugin_basename($this->file);
        $this->active = is_plugin_active($this->basename);
    }

    public function set_username($username) {
        $this->username = $username;
    }

    public function set_repository($repository) {
        $this->repository = $repository;
    }

    public function authorize($token) {
        $this->authorize_token = $token;
    }

    private function make_request_uri($uri) {
        return sprintf($uri, $this->username, $this->repository);
    }

    private function get_github_response($request_uri) {
        if ($this->authorize_token) {
            $request_uri = add_query_arg(array(
                'access_token' => $this->authorize_token
            ), $request_uri); 
        }
        $response = wp_remote_get($request_uri);
        $httpCode = wp_remote_retrieve_response_code($response);
        if ($httpCode != 200) {
            apurata_log(sprintf('Apurata Github responded with http_code %s at %s', $httpCode, $request_uri));
            return false;
        }
        $body_response = json_decode(wp_remote_retrieve_body($response), true);
        if (!$body_response) {
            apurata_log('Apurata Github response does not contain body');
            return false;
        }  
        return $body_response;
    }
    private function check_repository_files() {
        $request_uri = $this->make_request_uri('https://api.github.com/repos/%s/%s/git/trees/master?recursive=1');
        if ($body_response = $this->get_github_response($request_uri)) {
            $tree = $body_response['tree'];
            $necessary_files = array('readme.txt','apurata-update.php','woocommerce-apurata-payment-gateway.php');
            $repository_files = array();
            foreach($tree as $path){
                $repository_files[] = $path['path'];
            }
            foreach ($necessary_files as $file) {
                if (!in_array($file,$repository_files)) {
                    apurata_log('Error: The file ' . $file . ' was not found');
                    return false;
                }
            }
            return true;
        }
    }
    private function get_repository_info() {
        if (is_null($this->github_response)) { 
            $request_uri = $this->make_request_uri('https://api.github.com/repos/%s/%s/releases');
            if ($body_response = $this->get_github_response($request_uri)){
                if(is_array($body_response)) {
                    $body_response = current($body_response);
                }
                $required_parameters = array('zipball_url', 'tag_name', 'published_at');
                foreach ($required_parameters as $parameter) {
                    if(!isset($body_response[$parameter])){
                        apurata_log("Apurata Github response does not contain the '" . $parameter . "'parameter.");
                        return false;
                    }
                }
                if (!$this->check_repository_files()) {
                    return false;
                }
                if ($this->authorize_token) {
                    $body_response['zipball_url'] = add_query_arg( array(
                        'access_token' => $this->authorize_token
                    ), $body_response['zipball_url']);   
                }
                $this->github_response = $body_response;
                return true;
            }  
        }
        return false;
    }

    

    public function modify_transient($transient) {
        error_log("entre a la funcion");
        if(property_exists($transient, 'checked') && $checked = $transient->checked) {
            error_log("pero no checkeo los plugins");
            if (!$this->get_repository_info())
                return $transient;
            $current_version = $checked[$this->basename];
            $new_version = substr($this->github_response['tag_name'], 1);
            $out_of_date = version_compare($new_version, $current_version, 'gt');
            if ($out_of_date) {
                $new_files = $this->github_response['zipball_url'];
                $slug = current(explode('/', $this->basename) );
                $plugin = array(
                    'url' => $this->plugin['PluginURI'],
                    'slug' => $slug,
                    'package' => $new_files,
                    'new_version' => $new_version
                );
                $transient->response[$this->basename] = (object)$plugin;
            }
        }
        return $transient; 
    }

    public function plugin_popup($result, $action, $args) { 
        if (!empty($args->slug)) {
            if ($args->slug == current(explode('/' , $this->basename ))) { 
                $plugin = array(
                    'name'				=> $this->plugin['Name'],
                    'slug'				=> $this->basename,
                    'requires'			=> $this->plugin['WC requires at least'],
                    'tested'			=> $this->plugin['WC tested up to'],
                    'author'			=> $this->plugin["AuthorName"],
                    'author_profile'	=> $this->plugin["AuthorURI"],
                    'homepage'			=> $this->plugin["PluginURI"],
                    'short_description' => $this->plugin["Description"],
                );
                if ($this->get_repository_info()) {
                    $plugin += array(
                        'version'			=> $this->github_response['tag_name'],
                        'last_updated'		=> $this->github_response['published_at'],
                        'sections'			=> array(
                            'Description'	=> $this->plugin["Description"],
                            'Updates'		=> $this->github_response['body'],
                        ),
                        'download_link'		=> $this->github_response['zipball_url']
                    );
                }
                return (object) $plugin;
            }
        }
        return $result; 
    }

    public function after_install( $response, $hook_extra, $result ) {
        global $wp_filesystem;
        error_log("ACTUALIZACION");
        $install_directory = plugin_dir_path( $this->file );
        $wp_filesystem->move( $result['destination'], $install_directory );
        $result['destination'] = $install_directory;
        if ( $this->active ) { 
            activate_plugin($this->basename);
        }
        return $result;
    }
}
?>