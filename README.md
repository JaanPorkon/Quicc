# Quicc
Quicc is a really simple PHP framework for building simple REST APIs.

# Getting Started
Using Quicc is very simple. All you have to do is include it to your file, set up your routes and that's it!

**Hello World**

```
require_once 'quicc.class.php';

$q = new Quicc();

$q->get('/hello/', function()
{
    echo 'Hello World!';
});

$q->listen();
```

Available HTTP methods:

* BATCH
* DELETE
* GET
* HEAD
* PUT

# Usage

Quicc includes methods for you to use when you wish to output JSON or XML.

```
$q->get('/hello/', function() use($q)
{
    $q->json(array('message' => 'Hello World!'));
});
```

Quicc allows you to use URL parameters as well. For example:

```
$q->get('/hello/{name}/', function($name)
{
    echo sprintf('Hello %s!', $name);
});
```

You can easily use query string as well:

```
$q->get('/hello/{name}/', function($name) use($q)
{
    echo sprintf('Hello %s! You are %d years old.', $name, $q->qs('age'));
});
```

To get POST data from forms or when JSON is posted, use the data method to get the value you need:

```
$q->post('/say-my-name/', function() use($q) {
    echo sprintf('Hello %s', $q->data('name'));
});
```

When using URL parameters, you are able to define the parameter type, for example:

```
$q->get('/user/{id:int}/', function($id) {
    echo sprintf('Querying user with an id %s', $id);
});
```

Available types:
* **int** - for integer
* **bool** - for boolean (0 or 1 or true or false)

You may use callback params in different order, but it is important that you name your parameters as you named them in your URL configuration:

```
$q->get('/users/{id:int}/page/{page}', function($page, $id) use($q) {
    echo sprintf('User id: %s, viewing page: %s', $id, $page);
});
```

Public methods:

* **json($array)** - outputs JSON
* **xml($array)** - outputs XML
* **get_params()** - returns an array of URL parameters with their values
* **qs($key)** - gets a value from query string
* **data($key)** - gets the value from POST body (set content type to "application/json" when sending JSON)


# License
This project is licensed under the MIT License - see the [LICENSE.md](LICENSE.md) file for details.