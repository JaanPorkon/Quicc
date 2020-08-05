<?php

class Quicc
{
	private $routes = [];
	private $route = null;
	private $uri = null;
	private $params = null;

	private function parse_route($uri, $callback)
	{
		$route = array();
		$pieces = explode('/', $uri);
		$pattern = array();
		$params = array();

		foreach($pieces as $piece)
		{
			$piece = trim($piece);

			if(substr($piece, 0, 1) === '{' && substr($piece, strlen($piece) - 1, strlen($piece)) === '}')
			{
				$pattern[] = '(.*?)';
				$params[] = substr($piece, 1, strlen($piece) - 2);
			}
			else
			{
				$pattern[] = $piece;
			}
		}

		$route['name'] = $uri;
		$route['pattern'] = sprintf('/%s/', join('\/', $pattern));
		$route['params'] = $params;
		$route['callback'] = $callback;

		return $route;
	}

	private function validate_method($allowed_method) {
		if(strtoupper($_SERVER['REQUEST_METHOD']) !== $allowed_method) {
			header($_SERVER["SERVER_PROTOCOL"]." 405 Method Not Allowed", true, 405);
			exit;
		}
	}

	public function __call($name, $args) {
		$allowed_calls = array('batch', 'delete', 'get', 'head', 'post', 'head', 'put');

		if(!in_array($name, $allowed_calls)) {
			throw new Exception(sprintf('"%s" is not an allowed method!', $name));
		}

		$this->validate_method(strtoupper($name));
		$this->routes[$args[0]] = $this->parse_route($args[0], $args[1]);
	}

	private function detect_route() {
		foreach($this->routes as $name => $route) {
			if(preg_match($route['pattern'], $this->uri) !== 0) {
				return $route;
			}
		}
	}

	private function detect_params() {
		preg_match_all($this->route['pattern'], $this->uri, $matches);

		$params = array();

		for($i = 0; $i < count($this->route['params']); $i++) {
			$params[$this->route['params'][$i]] = $matches[1][$i];
		}

		$ref = new ReflectionFunction($this->route['callback']);

		if($ref->getNumberOfRequiredParameters() > count($params)) {
			$required_parameters = array();

			foreach ($ref->getParameters() as $param) {
				$required_parameters[] = $param->name;
			}

			throw new Exception(sprintf('Route callback does not have enough params. Available params: %s. Required params: %s', join(', ', $this->route['params']), join(', ', $required_parameters)));
		}

		return $params;
	}

	public function json($response) {
		header('Content-Type: application/json');
		echo json_encode($response);
	}

	public function xml($response) {
		header('Content-Type: text/xml');
		echo $this->build_xml($response);
	}

	private function build_xml($data, $root = null, $xml = null) {
		if(!is_array($data)) {
			throw new Exception('In order to build XML response you must supply an array!');
		}

		$_xml = $xml;

		if(is_null($_xml)) {
			$_xml = new SimpleXMLElement(!is_null($root) ? $root : '<root />');
		}

		foreach($data as $key => $value) {
			if(is_array($value)) {
				$this->build_xml($value, $key, $_xml->addChild($key));
			} else {
				$_xml->addChild($key, $value);
			}
		}

		return $_xml->asXML();
	}

	public function listen()
	{
		$this->uri = str_replace($_SERVER['SCRIPT_NAME'], '', $_SERVER['PHP_SELF']);
		$this->route = $this->detect_route();
		$this->params = $this->detect_params();

		call_user_func_array($this->route['callback'], $this->params);
	}
}