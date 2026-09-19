<?php
/**
 * Network Templates
 *
 * @package CertificateManager
 */

namespace CertificateManager\Multisite;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Network templates class
 */
class NetworkTemplates {
	
	/**
	 * Template repository
	 *
	 * @var Repositories\TemplateRepository
	 */
	private $template_repo;
	
	/**
	 * Constructor
	 *
	 * @param Repositories\TemplateRepository $template_repo Template repository.
	 */
	public function __construct( $template_repo ) {
		$this->template_repo = $template_repo;
	}
	
	/**
	 * Get network templates
	 *
	 * @return array Array of network templates.
	 */
	public function get_network_templates() {
		return method_exists( $this->template_repo, 'get_network_templates' ) ? $this->template_repo->get_network_templates() : array();
	}
	
	/**
	 * Make a template network-wide
	 *
	 * @param int $template_id Template ID.
	 * @return bool True on success, false on failure.
	 */
	public function make_network_template( $template_id ) {
		return method_exists( $this->template_repo, 'set_network_template' ) ? $this->template_repo->set_network_template( $template_id, true ) : false;
	}
	
	/**
	 * Make a template site-specific
	 *
	 * @param int $template_id Template ID.
	 * @return bool True on success, false on failure.
	 */
	public function make_site_template( $template_id ) {
		return method_exists( $this->template_repo, 'set_network_template' ) ? $this->template_repo->set_network_template( $template_id, false ) : false;
	}
	
	/**
	 * Get templates available to a site
	 *
	 * @param int $site_id Site ID. Default is current site.
	 * @return array Array of templates.
	 */
	public function get_site_templates( $site_id = 0 ) {
		$site_id = $site_id ?: get_current_blog_id();
		
		return method_exists( $this->template_repo, 'get_site_templates' ) ? $this->template_repo->get_site_templates( $site_id ) : array();
	}
}
