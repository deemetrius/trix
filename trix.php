<?php
/*		mailto: sm0ke999@yandex.ru
		author: Dimitry Tumaly

Here converter of template files into php code.
Minor compatibility of S-M-A-R-T-Y syntax is supported.
To use destination PHP file made by trix ... no need to include THIS trix file anymore.
Class 'trix' is just template compiler!

So I intended to make it simple and fast as I can:
* by Keeping file_date_time checking off-board
* by Minimzing the verification of syntax in template files
* by Not to put any standart modifiers inside
That fat guy spreaded to lots of files is not my style

requirements: PHP 7

// Once we got compiled file ... we able to use it very easy ... without trix
$fruit = 'banana'; // assign
include 'tpl_c/sample_template.php'; // fetch
*/

abstract class base_trix // modifiers layer
{
	// local
	private $mod_map = [];
	
	public function add_modifiers($map)
	{ self::mod_join_to($map, $this->mod_map); }
	
	// common
	static private $mod_map_common = [];
	
	static public function add_common_modifiers($map)
	{ self::mod_join_to($map, self::$mod_map_common); }
	
	// find
	protected function modifier_find($alias)
	{ return $this->mod_map[$alias] ?? self::$mod_map_common[$alias] ?? false; }
	
	// apply
	static protected function modifier_apply($expression, $mod)
	{ return is_array($mod) ? ($mod[0] . $expression . $mod[1]) : ($mod .'( '. $expression .' )'); }
	
	// internal
	static protected function mod_join_to($map, &$hive)
	{
		if( !is_array($hive) ) $hive = [];
		
		if( is_array($map) && count($map) )
		foreach( $map as $alias => $expression )
		if( is_string($expression) )
		$hive[$alias] = $expression;
		elseif( is_array($expression) && count($expression) > 1 )
		{
			$expression = array_values($expression);
			if( is_string($expression[0]) && is_string($expression[1]) )
			$hive[$alias] = $expression;
		}
	}
}

class parser_trix extends base_trix // conversion layer
{
	const name_char = 'a-zA-Z0-9_';
	
	public
	$parts = [],
	$default_modifier = '',
	$flag_debug = false,
	$skip_count;
	
	static public function parse($code)
	{
		$pattern = '~\\{(\\*?|\\*.*?\\*|literal\\}.*?\\{\\/literal|[^\\*][^\\}]*)\\}~us';
		return preg_split($pattern, $code, null, PREG_SPLIT_DELIM_CAPTURE);
	}
	
	protected function deduce($v)
	{
		$result = null; $matches = null;
		do{
		
		// comment
		$pattern = '~^\\*.*\\*$~us';
		if( preg_match($pattern, $v, $matches) ) break;
		
		// literal
		$pattern = '~^literal\\}(?P<raw>.+)\\{\\/literal$~us';
		if( preg_match($pattern, $v, $matches) )
		{
			$result = $this->deduce_literal($v, $matches); break;
		}
		
		// plain tokens
		$tokens = [
		'ld'		=> [null, '{'],
		'ldelim'	=> [null, '{'],
		'rd'		=> [null, '}'],
		'rdelim'	=> [null, '}'],
		'/if'		=> ['tag', 'endif;'],
		'/each'		=> ['tag', 'endforeach;'],
		'/foreach'	=> ['tag', 'endforeach;'],
		'else'		=> ['tag', 'else:'],
		];
		if( $check = $tokens[$v] ?? false )
		{
			list($type, $result) = $check;
			$tokens = ['tag' => ['<?php ', ' ?>']];
			if( $check = $tokens[$type] ?? false )
			$result = $check[0] . $result . $check[1];
			break;
		}
		
		// variable
		$pattern = '~^\\$(['. self::name_char .']+)(?P<sub>(?:(?:\\.|\\-\\>)['. self::name_char .']+)*)(?P<mod>(?:\\|['. self::name_char .']*)*)$~u';
		if( preg_match($pattern, $v, $matches) )
		{
			// process default modifier (aka empty modifier) magic replacement if needed
			if( $this->default_modifier === '')
			$result = $this->deduce_variable($v, $matches);
			else
			{
				$count = null;
				$v2 = preg_replace('~\\|(?!['. self::name_char .'])~u', $this->default_modifier, $v, -1, $count);
				if( $count ) $v = $v2;
				if( preg_match($pattern, $v, $matches) )
				$result = $this->deduce_variable($v, $matches);
				else $this->skip_count += 1;
			}
			
			break;
		}
		
		// block opener
		$pattern = '~^(?P<type>if|elseif|foreach|each)\\s+(?P<expr>.*)$~u';
		if( preg_match($pattern, $v, $matches) )
		{
			$result = $this->deduce_block_opener($v, $matches); break;
		}
		
		$this->skip_count += 1;
		
		}while( 0 );
		return $result;
	}
	
	protected function deduce_literal($v, $matches)
	{ return $matches['raw'] ?? null; }
	
	protected function deduce_block_opener($v, $matches)
	{
		$repl = array('if'=>'if', 'elseif'=>'elseif', 'foreach'=>'foreach', 'each'=>'foreach');
		return '<?php '. $repl[$matches['type']] .'( '. $matches['expr'] .' ): ?>';
	}
	
	protected function deduce_variable($v, $matches)
	{
		$result = '$'. $matches[1];
		
		// process array elements and class properties
		if( array_key_exists($k='sub', $matches) )
		$result .= preg_replace('~\\.(['. self::name_char .']+)~u', "['\\1']", $matches[$k]);
		
		// process modifiers
		if( array_key_exists($k='mod', $matches) )
		{
			$mods = preg_split('~\\|~u', $matches[$k], -1, PREG_SPLIT_NO_EMPTY);
			if( count($mods) )
			foreach( $mods as $modifier )
			if( $mod = $this->modifier_find($modifier) )
			$result = $this->modifier_apply($result, $mod);
		}
		
		$debug = $this->flag_debug ? (' /* '. $v .' */') : '';
		return '<?php echo '. $result .';'. $debug .' ?>';
	}
}

class trix extends parser_trix
{
	public function __construct($file, $default_modifier = '')
	{ $this->load($file, $default_modifier); }
	
	public function load($file, $default_modifier = '')
	{
		if( is_string($default_modifier) && preg_match('~^\\|['. self::name_char .']+$~u', $default_modifier) )
		$this->default_modifier = $default_modifier;
		
		$this->parts = (is_string($file) && is_readable($file) && ($code=file_get_contents($file)) !== false)?
		self::parse($code) : [];
	}
	
	public function convert()
	{
		$this->skip_count = 0;
		foreach( $this->parts as $k => &$v )
		if( $k % 2 && mb_strlen($v) )
		$v = $this->deduce($v);
		
		return $this;
	}
	
	public function save_compiled($file)
	{
		return (is_string($file) && is_array($this->parts) /*&& is_writable($file)*/) ?
		file_put_contents($file, $this->parts) : null;
	}
}
