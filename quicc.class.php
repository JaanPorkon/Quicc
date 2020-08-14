<?php

class Quicc
{
	private $config = array('debug' => false, 'db' => null);
	private $routes = [];
	private $route = null;
	private $uri = null;
	private $params = null;
	public $db = null;

	/**
	 * Quicc constructor.
	 *
	 * @param null $config
	 */
	public function __construct($config = null)
	{
		if(!is_null($config))
		{
			$config = array_merge($this->config, $config);
			$this->config = $config;
		}

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

	/*
	 * Helpers
	 */

	/**
	 * Sets the content type header
	 *
	 * @param $value
	 */
	private function set_content_type($value)
	{
		header(sprintf('Content-Type: %s', $value));
	}

	/**
	 * Sets the response header
	 *
	 * @param $code
	 */
	private function set_response_header($code)
	{
		$responses = array(
			404 => 'Not Found',
			405 => 'Method Not Allowed'
		);

		header(sprintf('%s %d %s', $_SERVER['SERVER_PROTOCOL'], $code, $responses[$code]), true, $code);
		exit;
	}

	/*
	 * Quicc methods
	 */

	/**
	 * Counts pieces of the URL for pattern matching
	 *
	 * @param $value
	 * @return int
	 */
	private function count_url_pieces($value)
	{
		$counter = 0;

		foreach(explode('/', $value) as $pieces)
		{
			if(trim($pieces) !== '')
			{
				$counter++;
			}
		}

		return $counter;
	}

	/**
	 * Translates user created routes into proper structure
	 *
	 * @param $uri
	 * @param $method
	 * @param $callback
	 * @return array
	 */
	private function parse_route($uri, $method, $callback)
	{
		$route = array();
		$pieces = explode('/', $uri);
		$piece_count = $this->count_url_pieces($uri);
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
		$route['method'] = strtoupper($method);
		$route['piece_count'] = $piece_count;

		return $route;
	}

	/**
	 * Detects user function's parameters
	 *
	 * @return array
	 * @throws ReflectionException
	 */

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

	/**
	 * Validates the request method
	 *
	 * @param $allowed_method
	 */
	private function validate_method($allowed_method)
	{
		if(strtoupper($_SERVER['REQUEST_METHOD']) !== $allowed_method)
		{
			$this->set_response_header(405);
		}
	}

	/**
	 * Add a route to the routes list
	 *
	 * @param $name
	 * @param $args
	 * @throws Exception
	 */
	public function __call($name, $args)
	{
		$allowed_calls = array('batch', 'delete', 'get', 'head', 'post', 'head', 'put');

		if(!in_array($name, $allowed_calls))
		{
			throw new Exception(sprintf('"%s" is not an allowed method!', $name));
		}

		$this->routes[$args[0]] = $this->parse_route($args[0], $name, $args[1]);
	}

	/**
	 * Detects the route from a list of routes
	 *
	 * @return mixed|null
	 */
	private function detect_route()
	{
		$piece_count = $this->count_url_pieces($this->uri);

		foreach($this->routes as $name => $route)
		{
			if(preg_match($route['pattern'], $this->uri) !== 0 && $route['piece_count'] === $piece_count)
			{
				return $route;
			}
		}

		return null;
	}

	/**
	 * Detects and constructs parameters
	 *
	 * @return array
	 * @throws ReflectionException
	 */
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

				if($pieces[1] == 'int')
				{
					if(!is_numeric($value))
					{
						throw new Exception(sprintf('URL parameter "%s" is not an integer!', $pieces[0]));
					}

					$processed_params[$pieces[0]] = intval($value);
				}
				elseif($pieces[1] == 'bool')
				{
					if(!in_array($value, $booleans))
					{
						throw new Exception(sprintf('URL parameter "%s" is not a boolean!', $pieces[0]));
					}

					$processed_params[$pieces[0]] = boolval($value);
				}
				elseif($pieces[1] == 'email')
				{
					if(!filter_var($value, FILTER_VALIDATE_EMAIL))
					{
						throw new Exception(sprintf('URL parameter "%s" is not a valid email address!', $pieces[0]));
					}

					$processed_params[$pieces[0]] = $value;
				}
			}
			else
			{
				$processed_params[$key] = $value;
			}
		}

		return $processed_params;
	}

	/**
	 * Builds XML which will be returned during XML response
	 *
	 * @param $data
	 * @param null $root
	 * @param null $xml
	 * @return mixed
	 * @throws Exception
	 */
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

	/**
	 * Validates user input type
	 *
	 * @param $value
	 * @param $type
	 * @param $throw_exception
	 * @return null
	 * @throws Exception
	 */
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

		if(filter_var($value, $types[$type]))
		{
			return $value;
		}

		if($throw_exception)
		{
			throw new Exception(sprintf('Value "%s" is not valid "%s"!', $value, $type));
		}

		return null;
	}

	/**
	 * Returns the value of a query parameter
	 *
	 * @param $name
	 * @param null $type
	 * @param bool $throw_exception
	 * @return mixed|null
	 * @throws Exception
	 */
	public function qs($name, $type = null, $throw_exception = false)
	{
		$value = filter_input(INPUT_GET, $name, FILTER_SANITIZE_SPECIAL_CHARS);

		if(!is_null($type) && trim($name) !== '')
		{
			return $this->validate_input($value, $type, $throw_exception);
		}

		return $value;
	}

	/**
	 * Returns a value from POST request or JSON value from POST body
	 *
	 * @param $name
	 * @param null $type
	 * @param bool $throw_exception
	 * @return mixed|null
	 * @throws Exception
	 */
	public function data($name, $type = null, $throw_exception = false)
	{
		$value = null;

		if($_SERVER['CONTENT_TYPE'] === 'application/json')
		{
			$object = json_decode(file_get_contents('php://input'), true);

			if(array_key_exists($name, $object))
			{
				$value = $object[$name];
			}
		}
		else
		{
			$value = filter_input(INPUT_POST, $name, FILTER_SANITIZE_SPECIAL_CHARS);
		}

		if(!is_null($type) && trim($name) !== '')
		{
			return $this->validate_input($value, $type, $throw_exception);
		}

		return $value;
	}

	/**
	 * Returns URL params
	 *
	 * @return array|null
	 */
	public function get_params()
	{
		return $this->params;
	}

	/**
	 * Returns JSON response
	 *
	 * @param $response
	 */
	public function json($response)
	{
		$this->set_content_type('application/json');
		echo json_encode($response);
	}

	/**
	 * Returns XML response
	 *
	 * @param $response
	 * @throws Exception
	 */
	public function xml($response)
	{
		$this->set_content_type('text/xml');
		echo $this->build_xml($response);
	}

	/**
	 * Processes all routes
	 *
	 * @throws ReflectionException|Exception
	 */
	public function listen()
	{
		$this->uri = $_SERVER['REQUEST_URI'];
		$this->route = $this->detect_route();

		if(!is_null($this->route))
		{
			$this->validate_method($this->route['method']);

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