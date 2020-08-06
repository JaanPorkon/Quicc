<?php

class Quicc
{
	private $config = array('debug' => false, 'db' => null);
	private $routes = [];
	private $route = null;
	private $uri = null;
	private $params = null;
	public $db = null;

	public function __construct($config = null)
	{
		$this->config = array_merge($this->config, $config);

		if(array_key_exists('debug', $this->config) && $this->config['debug'])
		{
			ini_set('display_errors', true);
			error_reporting(E_ALL);
		}

		if(array_key_exists('db', $this->config) && !is_null($this->config['db']))
		{
			$this->db = new mysqli($this->config['db']['host'], $this->config['db']['user'], $this->config['db']['password'], $this->config['db']['name']);
		}
	}

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
				$param = substr($piece, 1, strlen($piece) - 2);
				$pattern_param = $param;
				$pieces = explode(':', $param);

				if(count($pieces) > 0)
				{
					$pattern_param = $pieces[0];
				}

				$pattern[] = sprintf('(?<%s>.*)', $pattern_param);
				$params[] = $param;
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

	private function get_callback_params()
	{
		$ref = new ReflectionFunction($this->route['callback']);
		$params = array();

		foreach($ref->getParameters() as $param)
		{
			$params[] = $param;
		}

		return $params;
	}

	private function set_response_header($code)
	{
		$responses = array(
			404 => 'Not Found',
			405 => 'Method Not Allowed'
		);

		header(sprintf('%s %d %s', $_SERVER['SERVER_PROTOCOL'], $code, $responses[$code]), true, $code);
		exit;
	}

	private function validate_method($allowed_method)
	{
		if(strtoupper($_SERVER['REQUEST_METHOD']) !== $allowed_method)
		{
			$this->set_response_header(405);
		}
	}

	public function __call($name, $args)
	{
		$allowed_calls = array('batch', 'delete', 'get', 'head', 'post', 'head', 'put');

		if(!in_array($name, $allowed_calls))
		{
			throw new Exception(sprintf('"%s" is not an allowed method!', $name));
		}

		$this->validate_method(strtoupper($name));
		$this->routes[$args[0]] = $this->parse_route($args[0], $args[1]);
	}

	private function detect_route()
	{
		foreach($this->routes as $name => $route)
		{
			if(preg_match($route['pattern'], $this->uri) !== 0)
			{
				return $route;
			}
		}

		return null;
	}

	private function detect_params()
	{
		preg_match_all($this->route['pattern'], $this->uri, $matches);

		$params = array();

		foreach($this->route['params'] as $param)
		{
			$pieces = explode(':', $param);

			if(count($pieces) > 0)
			{
				$params[$param] = $matches[$pieces[0]][0];
			}
			else
			{
				$params[$param] = $matches[$param][0];
			}
		}

		$callback_params = $this->get_callback_params();

		if(count($callback_params) > count($params))
		{
			$required_parameters = array();

			foreach($callback_params as $param)
			{
				$required_parameters[] = $param->name;
			}

			throw new Exception(sprintf('Route callback does not have enough params. Available params: %s. Required params: %s', join(', ', $this->route['params']), join(', ', $required_parameters)));
		}

		$processed_params = array();

		foreach($params as $key => $value)
		{
			$pieces = explode(':', $key);

			if(count($pieces) == 2)
			{
				$booleans = array('0', '1', 'true', 'false');

				if($pieces[1] === 'int' && is_numeric($value))
				{
					$processed_params[$pieces[0]] = intval($value);
				}
				if($pieces[1] === 'int' && !is_numeric($value))
				{
					throw new Exception(sprintf('URL parameter "%s" is not an integer!', $pieces[0]));
				}
				elseif($pieces[1] === 'bool' && in_array($value, $booleans))
				{
					$processed_params[$pieces[0]] = boolval($value);
				}
				elseif($pieces[1] === 'bool' && !in_array($value, $booleans))
				{
					throw new Exception(sprintf('URL parameter "%s" is not a boolean!', $pieces[0]));
				}
				elseif($pieces[1] === 'email' && filter_var($value, FILTER_VALIDATE_EMAIL))
				{
					$processed_params[$pieces[0]] = $value;
				}
				elseif($pieces[1] === 'email' && !filter_var($value, FILTER_VALIDATE_EMAIL))
				{
					throw new Exception(sprintf('URL parameter "%s" is not a valid email address!', $pieces[0]));
				}
			}
			else
			{
				$processed_params[$key] = $value;
			}
		}

		return $processed_params;
	}

	private function build_xml($data, $root = null, $xml = null)
	{
		if(!is_array($data))
		{
			throw new Exception('In order to build XML response you must supply an array!');
		}

		$_xml = $xml;

		if(is_null($_xml))
		{
			$_xml = new SimpleXMLElement(!is_null($root) ? $root : '<root />');
		}

		foreach($data as $key => $value)
		{
			if(is_array($value))
			{
				$this->build_xml($value, $key, $_xml->addChild($key));
			}
			else
			{
				$_xml->addChild($key, $value);
			}
		}

		return $_xml->asXML();
	}

	private function validate_input($value, $type, $throw_exception)
	{
		$types = array(
			'int' => FILTER_VALIDATE_INT,
			'email' => FILTER_VALIDATE_EMAIL,
			'ip' => FILTER_VALIDATE_IP
		);

		if(!array_key_exists($type, $types))
		{
			throw new Exception(sprintf('Data type "%s" does not exist!', $type));
		}
		else
		{
			if(filter_var($type, $types[$type]))
			{
				return $value;
			}

			if($throw_exception)
			{
				throw new Exception(sprintf('Value "%s" is not valid "%s"!', $value, $type));
			}
		}

		return null;
	}

	public function qs($name, $type = null, $throw_exception = false)
	{
		$value = filter_input(INPUT_GET, $name, FILTER_SANITIZE_SPECIAL_CHARS);

		if(!is_null($type) && trim($name) !== '')
		{
			return $this->validate_input($value, $type, $throw_exception);
		}
		else
		{
			return $value;
		}
	}

	public function data($name, $type = null, $throw_exception = false)
	{
		if($_SERVER['CONTENT_TYPE'] === 'application/json')
		{
			$value = json_decode(file_get_contents('php://input'), true)[$name];
		}
		else
		{
			$value = filter_input(INPUT_POST, $name, FILTER_SANITIZE_SPECIAL_CHARS);
		}

		if(!is_null($type) && trim($name) !== '')
		{
			return $this->validate_input($value, $type, $throw_exception);
		}
		else
		{
			return $value;
		}
	}

	public function get_params()
	{
		return $this->params;
	}

	public function json($response)
	{
		header('Content-Type: application/json');
		echo json_encode($response);
	}

	public function xml($response)
	{
		header('Content-Type: text/xml');
		echo $this->build_xml($response);
	}

	public function listen()
	{
		$this->uri = $_SERVER['REQUEST_URI'];
		$this->route = $this->detect_route();

		if(!is_null($this->route))
		{
			$this->params = $this->detect_params();

			$ordered_params = array();

			foreach($this->get_callback_params() as $param)
			{
				if(!array_key_exists($param->name, $this->params))
				{
					throw new Exception(sprintf('The parameter "%s" is missing from your callback!', $param->name));
				}

				$ordered_params[] = $this->params[$param->name];
			}

			call_user_func_array($this->route['callback'], $ordered_params);
		}
		else
		{
			$this->set_response_header(404);
		}
	}
}