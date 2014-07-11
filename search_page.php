<?php 

  require_once 'vendor/autoload.php';

  use Symfony\Component\Yaml\Yaml;

  // Define some constants
  define(ROOT_NODE, 'ROOT');
  define(SEARCH_CONTEXT_MIN_WEIGHT, 0.8);


  // Load parameters from parameters.yml
  $settings = Yaml::parse(file_get_contents(__DIR__ .'/parameters.yml'));
  $parameters = $settings['parameters'];


  // Check if a query was given
  if (!isset($_GET['q'])) {
    header("Location: {$indexUrl}");
    exit;
  }


  // Create an ElasticSearch client
  $esclient = new Elasticsearch\Client(
    array('hosts' => 
      array($parameters['elastic.server'])
    )
  );



  // Retrieve all contexts 
  $contexts = $esclient->search(array(
    'index' => $parameters['elastic.index'],
    'type' => 'context',
    'size' => 1000,
    'body' => array(
      'sort' => array("name.untouched"),
      'query' => array(
        'match_all' => array()
      )
    )
  ));
  $contexts = $contexts['hits']['hits'];

  $contextsByUrl = array();
  foreach ($contexts as $context) {
    $contextsByUrl[$context["_source"]["url"]] = $context["_source"];
  }
  $contextsByUrl['ROOT'] = array(
    "name" => "DeltaExpertise"
  );

  // Get search results

  $search_results = $esclient->search(array(
    'index' => 'hzbwnature',
    // 'type' => 'intentional_element',
    'size' => 100,
    'body' => array(
      'query' => array(
        'multi_match' => array(
          'query' => $_GET['q'],
          'fields' => array(
            "skos:prefLabel^3",
            "skos:definition",
            "title^3",
            "content",
            "concerns_readable^2",
            "context_readable^2"
          )
        )
      )
    )
  ));
  $search_results = $search_results['hits']['hits'];



  // This helper returns the VN page that should be visited 
  // when a link is clicked (if available)

  function vn_url ($source) {
    if (count($source['vn_pages'])>0)
      return $source['vn_pages'][0];
    else
      return $source['url'];   
  }




  // Construct a data structure that will be used
  // to quickly find parent- and children nodes of the
  // context tree
  // The data structures are two dictionaries: 
  // parents[url] = superurl, and
  // children[url] = [child,child,child]

  $parents = array();
  $children = array();
  $info = array();
  foreach ($contexts as $context) {
    $url = $context['_source']['url'];
    $super = $context['_source']['supercontext'];
    $parents[urldecode($url)] = urldecode($super);
    $children[$super][] = $url;
    $info[$url] = $context['_source'];
  }

  $info[ROOT_NODE] = array(
    'name' => 'Alle Contexten',
    'url' => ''
  );

  $contextExists = function ($context) use ($parents) {
    return isset($parents[$context]);
  };


  // Determine the 'search context'

  // (1) Define an array that will store the weight
  //     that is attached to all context for the current
  //     search, and define a function that adds weight
  //     to the nodes recursively.
  
  $weights = array();

  $addWeight = function ($context_url, $weight_to_add) use (&$weights, $parents, &$addWeight, $contextExists) {
    
    // we SKIP invalid contexts
    if ($context_url != ROOT_NODE && !$contextExists($context_url)) return;

    // printf("<b>Add weight to %s.</b><br>\n",$context_url);

    if (isset($weights[$context_url])) {
      $weights[$context_url] += $weight_to_add;
    } else {
      $weights[$context_url] = $weight_to_add;
    }

    // recursive step
    if ($context_url != ROOT_NODE) {
      // printf("Add weight to parent %s.<br>\n",$parents[$context_url]);
      $addWeight($parents[$context_url] , $weight_to_add);
    }

  };


  // (2) Initialize the weights for the current search

  foreach ($search_results as $result) {
    $weight = $result['_score'];
    foreach ($result['_source']['context'] as $context) {
      $addWeight($context, $weight);
    }
  }



  // The search context should have a minimum weight of
  // MIN_PERCENTAGE * WEIGHT[ROOT]
  $min_weight = SEARCH_CONTEXT_MIN_WEIGHT * $weights[ROOT_NODE];



  // This searches for the child with the highest percentage

  $findSearchContext = function ($context_url, $min_weight) use (&$findSearchContext, $weights, $children) {

    // Collect the children of the node and their weights
    $kiddos = $children[$context_url];
    $kiddo_weights = array_map(function ($kid) use ($weights) {
      if (isset($weights[$kid])) return $weights[$kid];
      else return 0.;
    }, $kiddos);


    // If there is a child with a weight > $min_weight, recurse,
    // otherwise, return this context as the search context.
    if (count($kiddos) > 0 && max($kiddo_weights) >= $min_weight) {
      $key = array_keys($kiddo_weights, max($kiddo_weights));
      $key = $key[0];
      return $findSearchContext($kiddos[$key],$min_weight);
    } else {
      return $context_url;
    }
  };

  // Determine the search context

  $search_context = $findSearchContext(ROOT_NODE, $min_weight);


  // And store some info about it

  $search_context_info = $info[$search_context];



  // This traces a context's parents and returns them in an array

  $trace = function ($context_url) use (&$trace, $parents, $contextExists) {


    // we SKIP invalid contexts
    if ($context_url != ROOT_NODE && !$contextExists($context_url)) return array();

    if ($context_url == ROOT_NODE) 
      return array(md5(ROOT_NODE));
    else {
      $parent_trace = $trace($parents[$context_url]);
      array_push($parent_trace,md5($context_url));
      return $parent_trace;
    }
  };



  // Count the number of results per context
  // in a dictionary like $counts[md5 of url] = int.
  $counts = array();
  $contextResults = array();
  foreach ($search_results as $result) {
    $url = urldecode($result['_source']['url']);
    foreach ($result['_source']['context'] as $cntxt) {
      $tr = $trace(urldecode($cntxt));
      foreach ($tr as $t) {
        $counts[$t]++;
        $contextResults[$t][md5($result['_source']['url'])] = $result;
      }
    }
  }

  $urlCounts = function ($url) use ($counts) {
    $md5 = md5($url);
    if (isset($counts[$md5])) return $counts[$md5];
    else return 0;
  };

  $recursiveResultContents = function ($context, $nesting = 0) use (&$recursiveResultContents, $contextsByUrl, $children, $contextResults)
  {
    if ($nesting > 5) {
      return "";
    }
    $current = $contextsByUrl[$context];

    $results = $contextResults[md5($context)];

    ob_start();

    printf("<div class=\"search-context-block closed\" data-count=\"%s\">", count($results));

    printf("  <div class=\"search-context-title\"><span class=\"open-closed-indicator\">s</span> %s</div>\n", $current['name']);

    foreach ($children[$context] as $child) {
      echo $recursiveResultContents($child, $nesting + 1);
    }

    printf("  <ul class=\"search-context-results minify\">\n");
    foreach ($results as $r) {
      printf("    <li class=\"search-result\"><a href=\"%s\">%s</a></li>",vn_url($r['_source']['suggest']['payload']), htmlentities($r['_source']['title']));
    }
    printf("  </ul>\n");
    printf("</div>");

    return ob_get_clean();
  };

?>
<div id="sectionNav"></div>
<div id="body">
  
  <h1>Zoekresultaat &ldquo;<?php echo htmlentities($_GET['q']) ?>&rdquo;</h1>

  <input type="hidden" id="search-query" value="<?php echo htmlentities($_GET['q']) ?>">

  <div id="mw-content-text" lang="nl" dir="ltr" class="mw-content-ltr">
    <!--<p class="count-string"><?php echo count($contextResults[md5('ROOT')]) ?> zoekresultaten</p>-->

    <div id="page">

      <?php echo $recursiveResultContents('ROOT'); ?>

    </div>

  </div>
</div>