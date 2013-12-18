<?php

use Pyro\Module\Pages\Model\Page;

/**
 * The public controller for the Pages module.
 *
 * @author		PyroCMS Dev Team
 * @package		PyroCMS\Core\Modules\Pages\Controllers
 */
class Pages extends Public_Controller
{
	/**
	 * Constructor method
	 */
	public function __construct()
	{
		parent::__construct();

		// This basically keeps links to /home always pointing to
		// the actual homepage even when the default_controller is
		// changed

		// No page is mentioned and we are not using pages as default
		//  (eg blog on homepage)
		if ( ! $this->uri->segment(1) and $this->router->default_controller != 'pages') {
			redirect('');
		}
	}

	/**
	 * Catch all requests to this page in one mega-function.
	 *
	 * @param string $method The method to call.
	 */
	public function _remap($method)
	{
		// This page has been routed to with pages/view/whatever
		if ($this->uri->rsegment(1, '').'/'.$method === 'pages/view') {
			$url_segments = $this->uri->total_rsegments() > 0 ? array_slice($this->uri->rsegment_array(), 2) : null;
		}

		// not routed, so use the actual URI segments
		else {
			if (($url_segments = $this->uri->uri_string()) === 'favicon.ico') {
				$favicon = Asset::get_filepath_img('theme::favicon.ico');

				if (file_exists(FCPATH.$favicon) && is_file(FCPATH.$favicon)) {
					header('Content-type: image/x-icon');
					readfile(FCPATH.$favicon);
				} else {
					set_status_header(404);
				}

			}

			$url_segments = $this->uri->total_segments() > 0 ? $this->uri->segment_array() : null;
		}

		// If it has .rss on the end then parse the RSS feed
		$url_segments && preg_match('/.rss$/', end($url_segments))
			? $this->_rss($url_segments)
			: $this->_page($url_segments);
	}

	/**
	 * Page method
	 *
	 * @param array $url_segments The URL segments.
	 */
	public function _page($url_segments)
	{
		// If we are on the development environment,
		// we should get rid of the cache. That ways we can just
		// make updates to the page type files and see the
		// results immediately.
		if (ENVIRONMENT === PYRO_DEVELOPMENT) {
			$this->cache->forget('Page');
		}

		// GET THE PAGE ALREADY. In the event of this being the home page $url_segments will be null
		// $page = $this->cache->method('Page::findByUri', array($url_segments, true));
		$page = Page::findByUri($url_segments, true);

		// If page is missing or not live (and the user does not have permission) show 404
		if ( ! $page or ($page->status === 'draft' and ! ci()->current_user->hasAccess(array('put_live', 'edit_live')))) {
			// Load the '404' page. If the actual 404 page is missing (oh the irony) bitch and quit to prevent an infinite loop.
			// if ( ! ($page = $this->cache->method('page_m', 'get_by_uri', array('404'))))
			if ( ! ($page = Page::findByUri(404))) {
				show_error('The page you are trying to view does not exist and it also appears as if the 404 page has been deleted.');
			}
		}

		// the home page won't have a base uri
		isset($page->base_uri) OR $page->base_uri = $url_segments;

		// If this is a homepage, do not show the slug in the URL
		if ($page->is_home and $url_segments) {
			redirect('', 'location', 301);
		}

		// If the page is missing, set the 404 status header
		if ($page->slug == 404) {
			$this->output->set_status_header(404);
		}

		// Nope, it is a page, but do they have access?
		elseif ($page->restricted_to) {

			// My favorite.. EXPLODE
			$page->restricted_to = (array) explode(',', $page->restricted_to);

			// Grab user group IDs
			$user_groups = isset($this->current_user->id) ? $this->current_user->groups->lists('id') : array();

			// Get the similarities between groups / restriced group IDs
			$matches = array_intersect($page->restricted_to, $user_groups);

			// Are they logged in and an admin or a member of the correct group?
			if ( ! $user_groups or (! in_array(1, $user_groups) and empty($matches))) {
				// send them to login but bring them back when they're done
				$this->session->set_userdata('redirect_to', $redirect_to = implode('/', $url_segments));
				redirect('users/login');
			}
		}

		// We want to use the valid uri from here on. Don't worry about segments passed by Streams or
		// similar. Also we don't worry about breadcrumbs for 404
		if ($url_segments = explode('/', $page->base_uri) and count($url_segments) > 1) {
			// we dont care about the last one
			array_pop($url_segments);

			$parents = $breadcrumb_segments = array();

			// TODO Cache me! Phil delete it
			foreach ($url_segments as $segment) {
				$breadcrumb_segments[] = $segment;

				$parents[] = Page::findByUri($breadcrumb_segments, true);
			}

			foreach ($parents as $parent_page) {
				$this->template->set_breadcrumb($parent_page->title, $parent_page->uri);
			}
		}

		// If this page has an RSS feed, show it
		if ($page->rss_enabled) {
			$this->template->append_metadata('<link rel="alternate" type="application/rss+xml" title="'.$page->meta_title.'" href="'.site_url(uri_string().'.rss').'" />');
		}

		// Set pages layout files in your theme folder
		if ($this->template->layout_exists($page->uri.'.html')) {
			$this->template->set_layout($page->uri.'.html');
		}

		// If a Page Type has a Theme Layout that exists, use it
		if ( ! empty($page->type->theme_layout) and $this->template->layout_exists($page->type->theme_layout)
			// But Allow that you use layout files of you theme folder without override the defined by you in your control panel
			and ($this->template->layout_is('default.html') or $page->type->theme_layout !== 'default.html')
		) {
			$this->template->set_layout($page->type->theme_layout);
		}

		// ---------------------------------
		// Metadata
		// ---------------------------------

		$page->meta_title = $this->parser->parse_string($page->meta_title, array('current_user' => ci()->current_user), true);
		$page->meta_description = $this->parser->parse_string($page->meta_description, array('current_user' => ci()->current_user), true);

		// First we need to figure out our metadata. If we have meta for our page,
		// that overrides the meta from the page layout.
		$meta_title = ($page->meta_title ?: $page->type->meta_title);
		$meta_description = ($page->meta_description ?: $page->type->meta_description);
		$meta_keywords = '';

		$keyword_hash = $page->meta_keywords ?: $page->type->meta_keywords;

		if ($keyword_hash) {
			$meta_keywords = Keywords::get_string($page->meta_keywords);
		}

		$meta_robots = $page->meta_robots_no_index ? 'noindex' : 'index';
		$meta_robots .= $page->meta_robots_no_follow ? ',nofollow' : ',follow';
		// They will be parsed later, when they are set for the template library.

		// Not got a meta title? Use slogan for homepage or the normal page title for other pages
		if (! $meta_title) {
			$meta_title = $page->is_home ? Settings::get('site_slogan') : $page->title;
		}

		// Set the title, keywords, description, and breadcrumbs.
		$this->template->title($this->parser->parse_string($meta_title, $page, true))
			->set_metadata('keywords', $this->parser->parse_string($meta_keywords, $page, true))
			->set_metadata('robots', $meta_robots)
			->set_metadata('description', $this->parser->parse_string($meta_description, $page, true))
			->set_breadcrumb($page->title);

		// Parse the CSS so we can use tags like {{ asset:inline_css }}
		// #foo {color: red} {{ /asset:inline_css }}
		// to output css via the {{ asset:render_inline_css }} tag. This is most useful for JS
		$css = $this->parser->parse_string($page->type->css.$page->css, $this, true);

		// there may not be any css (for sure after parsing Lex tags)
		if ($css) {
			$this->template->append_metadata('
				<style type="text/css">
					'.$css.'
				</style>', 'late_header');
		}

		$js = $this->parser->parse_string($page->type->js.$page->js, $this, true);

		// Add our page and page layout JS
		if ($js) {
			$this->template->append_metadata('
				<script type="text/javascript">
					'.$js.'
				</script>');
		}

		// If comments are enabled, go fetch them all
		if (Settings::get('enable_comments')) {
			// Load Comments so we can work out what to do with them
			$this->load->library('comments/comments', array(
				'entry_id' 		=> $page->id,
				'entry_title' 	=> $page->title,
				'module' 		=> 'pages',
				'singular' 		=> 'pages:page',
				'plural' 		=> 'pages:pages',
			));
		}

		// Get our stream.
		//$this->load->driver('Streams');
		//$stream = $this->streams_m->get_stream($page->type->stream_id);

		// We are going to pre-build this data so we have the data
		// available to the template plugin (since we are pre-parsing our views).
		$template = $this->template->build_template_data();

		// Parse our view file. The view file is nothing
		// more than an echo of $page->layout->body and the
		// comments after it (if the page has comments).

		$attributes = $page->getAttributes();

		$attributes = array_merge($attributes, ($page->entry and ! $_POST) ? $page->entry->asPlugin()->getAttributes() : array());

		$html = $this->template->load_view('pages/page', array_merge(array('page' => $page), $attributes), false);
		
		$view = $this->parser->parse_string($html, $page, true, false, array(
			'stream' => $page->type->stream->stream_slug,
			'namespace' => $page->type->stream->stream_namespace,
			'id_name' => 'entry_id'
		));

		if ($page->slug == '404')
		{
			log_message('error', 'Page Missing: '.$this->uri->uri_string());

			// things behave a little differently when called by MX from MY_Exceptions' show_404()
			exit($this->template->build($view, array('page' => $page), false, false, true, $template));
		}

		$this->template->build($view, array('page' => $page), false, false, true, $template);
	}

	/**
	 * RSS method
	 *
	 * @param array $url_segments The URL segments.
	 *
	 * @return null|void
	 */
	public function _rss($url_segments)
	{
		// Remove the .rss suffix
		$url_segments += array(preg_replace('/.rss$/', '', array_pop($url_segments)));

		// Fetch this page from the database via cache
		// TODO Cache me, Phil delete it
		$page = Page::findByUri($url_segments, true);

		// We will need to know if we should include draft pages in the feed later on too, so save it.
		$include_draft = ! empty($this->current_user) AND $this->current_user->group !== 'admin';

		// If page is missing or not live (and not an admin) show 404
		if (empty($page) or ($page->status == 'draft' and $include_draft) or ! $page->rss_enabled) {
			// Will try the page then try 404 eventually
			$this->_page('404');
			return;
		}

		// If the feed should only show live pages
		$status = $include_draft ? null : 'live';

		// Hit the query through the cache.
		$children = $this->cache->method('Page', 'findByIdAndStatus', array($id, $status));

		$data = array(
			'rss' => array(
				'title' => ($page->meta_title ?: $page->title).' | '.Settings::get('site_name'),
				'description' => $page->meta_description,
				'link' => site_url($url_segments),
				'creator_email' => Settings::get('contact_email'),
				'items' => array(),
			),
		);

		if ( ! empty($children)) {
			$this->load->helper('xml');

			foreach ($children as &$row) {
				$row->link = $row->uri ?: $row->slug;
				$row->created_on = date(DATE_RSS, $row->created_on);

				$data['rss']['items'][] = array(
					//'author' => $row->author,
					'title' => xml_convert($row->title),
					'link' => $row->link,
					'guid' => $row->link,
					'description' => $row->meta_description,
					'date' => $row->created_on
				);
			}
		}

		// We are outputing RSS/Atom here... let them know.
		$this->output->set_header('Content-Type: application/rss+xml');
		$this->load->view('rss', $data);
	}
}
