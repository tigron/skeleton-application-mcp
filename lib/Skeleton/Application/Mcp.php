<?php
/**
 * Skeleton Core Application class for MCP
 */

namespace Skeleton\Application;

class Mcp extends \Skeleton\Core\Application {

	/**
	 * Tool path
	 *
	 * @var string $tool_path
	 * @access public
	 */
	public $tool_path = null;

	/**
	 * Tool namespace
	 *
	 * @var string $tool_namespace
	 * @access public
	 */
	public $tool_namespace = null;

	/**
	 * Security path
	 *
	 * @var string $security_path
	 * @access public
	 */
	public $security_path = null;

	/**
	 * Security namespace
	 *
	 * @var string $security_namespace
	 * @access public
	 */
	public $security_namespace = null;

	/**
	 * Component path
	 *
	 * @var string $component_path
	 * @access public
	 */
	public $component_path = null;

	/**
	 * Component namespace
	 *
	 * @var string $component_namespace
	 * @access public
	 */
	public $component_namespace = null;

	/**
	 * Get details
	 *
	 * @access protected
	 */
	protected function get_details(): void {
		parent::get_details();

		$this->tool_path = $this->path . '/tool/';
		$this->security_path = $this->path . '/security/';
		$this->component_path = $this->path . '/component/';

		$this->tool_namespace = "\\App\\" . ucfirst($this->name) . "\\Tool\\";
		$this->security_namespace = "\\App\\" . ucfirst($this->name) . "\\Security\\";
		$this->component_namespace = "\\App\\" . ucfirst($this->name) . "\\Component\\";

		$autoloader = new \Skeleton\Core\Autoloader();
		$autoloader->add_namespace($this->tool_namespace, $this->tool_path);
		$autoloader->add_namespace($this->security_namespace, $this->security_path);
		$autoloader->add_namespace($this->component_namespace, $this->component_path);
		$autoloader->register();
	}

	/**
	 * Load the config
	 *
	 * @access protected
	 */
	protected function load_config(): void {
		/**
		 * Set some defaults
		 */
		$this->config->hostnames = [];

		// MCP server metadata (should be overwritten by the app)
		$this->config->server_name = $this->name;
		$this->config->server_version = '1.0.0';
		$this->config->protocol_version = '2025-06-18';
		$this->config->instructions = '';

		parent::load_config();
	}

	/**
	 * Run the application
	 *
	 * Basic sanity checks and wrapping responses in JSON-RPC
	 *
	 * @access public
	 */
	public function run(): void {
		// response is always JSON
		header('Content-Type: application/json');

		if (empty($_SERVER['REQUEST_METHOD']))
			$http_method = 'GET'; // default to an unsupported method
		else {
			$http_method = strtoupper($_SERVER['REQUEST_METHOD']);
		}

		if ($http_method !== 'POST') {
			\Skeleton\Core\Http\Status::code_405('Method Not Allowed');
			return;
		}

		// get the POST body
		$raw = file_get_contents('php://input');

		$handler = new \Skeleton\Application\Mcp\Handler($this);
		$response = $handler->handle($raw);

		// JSON-RPC needs an HTTP 202 Accepted if no outputs
		if ($response === null) {
			http_response_code(202);
			return;
		}

		echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
	}

	/**
	 * Get events
	 *
	 * @access protected
	 * @return array $events
	 */
	protected function get_events(): array {
		$parent_events = parent::get_events();
		$mcp_events = [
			'Tool' => '\\Skeleton\\Application\\Mcp\\Event\\Tool',
		];

		return array_merge($parent_events, $mcp_events);
	}

	/**
	 * Get all tools defined in the application
	 *
	 * @access public
	 * @return \Skeleton\Application\Mcp\Tool[] $tools
	 */
	public function get_tools(): array {
		if (!file_exists($this->tool_path)) {
			return [];
		}

		$files = \Skeleton\Application\Mcp\Util::rglob($this->tool_path . '*.php');
		$tools = [];

		foreach ($files as $file) {
			$file = str_replace($this->tool_path, '', $file);
			$pathinfo = pathinfo($file);

			$classname = '';
			if ($pathinfo['dirname'] !== '.') {
				$classname .= $pathinfo['dirname'] . '/';
			}
			$classname .= $pathinfo['filename'];
			$classname = str_replace(DIRECTORY_SEPARATOR, '\\', $classname);
			$classname = $this->tool_namespace . $classname;

			$tools[] = new $classname();
		}

		return $tools;
	}

	/**
	 * Get a single tool by its advertised name
	 *
	 * @access public
	 * @param string $name
	 * @return \Skeleton\Application\Mcp\Tool $tool
	 */
	public function get_tool(string $name): \Skeleton\Application\Mcp\Tool {
		foreach ($this->get_tools() as $tool) {
			if ($tool->get_name() === $name) {
				return $tool;
			}
		}

		throw new \Skeleton\Application\Mcp\Jsonrpc\Exception('Unknown tool ' . $name, -32602);
	}

	/**
	 * Get security
	 *
	 * @access public
	 * @return \Skeleton\Application\Mcp\Security[] $securities
	 */
	public function get_security(): array {
		if (!file_exists($this->security_path)) {
			return [];
		}

		$files = \Skeleton\Application\Mcp\Util::rglob($this->security_path . '*.php');
		$securities = [];

		foreach ($files as $file) {
			$file = str_replace($this->security_path, '', $file);
			$pathinfo = pathinfo($file);

			$classname = '';
			if ($pathinfo['dirname'] !== '.') {
				$classname .= $pathinfo['dirname'] . '/';
			}
			$classname .= $pathinfo['filename'];
			$classname = str_replace(DIRECTORY_SEPARATOR, '\\', $classname);
			$classname = $this->security_namespace . $classname;

			$securities[] = new $classname();
		}

		return $securities;
	}
}
