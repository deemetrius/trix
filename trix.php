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

	#	tpl/sample_template.tpl
<p>Do you like {$fruit|}?</p>

	#	tplc/sample_template.php
<p>Do you like <?php echo htmlspecialchars($fruit, ENT_COMPAT | ENT_HTML5, 'UTF-8', true); ?>?</p>

	#	USAGE of trix:

include 'trix.php'; // or via autoloader either
// describe common modifiers
$mods = [
	'escape' => ['htmlspecialchars(', ', ENT_COMPAT | ENT_HTML5, \'UTF-8\', true)'],
	'intval' => 'intval',
];
trix::add_common_modifiers($mods); // per-class
// run trix parser on source template file
$trix = new trix('tpl/sample_template.tpl', '|escape');
// local modifiers is allowed
$mods = [ 'floatval' => 'floatval' ];
$trix->add_modifiers($mods); // per-instance
// convert and save to destination PHP file
$trix->convert()->save_compiled('tplc/sample_template.php'); // note: Usually do not convert twice ^_^
echo $trix->skip_count; // zero is ok

	#	USAGE of result:

// Once we got compiled file ... we able to use it very easy ... without trix
$fruit = 'banana'; // assign
include 'tplc/sample_template.php'; // fetch
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
	static protected function modifier_apply($expression, $mod, $sub)
	{
		$sub = is_string($sub) ? ('[\''. $sub .'\']') : '';
		return is_array($mod) ? ($mod[0] . $expression . $mod[1]) : ($mod . $sub .'( '. $expression .' )');
	}
	
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
	const
	name_char = 'a-zA-Z0-9_',
	part_sub = '(?P<sub>(?:(?:\\.|\\-\\>)['. self::name_char .']+)*)',
	part_mod = '(?P<mod>(?:\\|['. self::name_char .']*(?:\\.['. self::name_char .']*)?)*)',
	part_member = '(?P<class>['. self::name_char .']+)(?P<dot>\\:|\\.)(?P<member>['. self::name_char .']+)';
	
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
		if( preg_match($pattern, $v, $matches) )
		break;
		
		// literal
		$pattern = '~^literal\\}(?P<raw>.+)\\{\\/literal$~us';
		if( preg_match($pattern, $v, $matches) )
		{
			$result = $matches['raw'] ?? null;
			break;
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
		
		/* // ksi ~ variant of next block about var
		'~^\\$([' % &.name_char ']+)' &.part_sub &.part_mod '$~u' =>
		$pattern preg_match $v $matches ? #0 :
		'~^&' % &.part_member &.part_sub &.part_mod '$~u' =>
		$pattern preg_match $v $matches ? #1 :
		# ; => $case is &bool ?
			$.default_modifier !== '' and
			$ process_default_modifiers $v and
			$pattern preg_match $v $matches ? $.skip_count += 1 :
			$ deduce_variable $v $matches $case => $result ;
			# break#
		;
		*/
		// variable (or static member)
		$case = null;
		$pattern = '~^\\$(['. self::name_char .']+)'. self::part_sub . self::part_mod .'$~u';
		if( preg_match($pattern, $v, $matches) ) $case = false;
		else
		{
			// static member
			$pattern = '~^&'. self::part_member . self::part_sub . self::part_mod .'$~u';
			if( preg_match($pattern, $v, $matches) ) $case = true;
		}
		if( is_bool($case) )
		{
			if(
				$this->default_modifier !== '' &&
				$this->process_default_modifiers($v) &&
				(!preg_match($pattern, $v, $matches)) // matches recapture
			) /*skip!*/ $this->skip_count += 1; else
			$result = $this->deduce_variable($v, $matches, $case);
			break;
		}
		
		// block opener
		$pattern = '~^(?P<type>if|elseif|foreach|each)\\s+(?P<expr>.*)$~u';
		if( preg_match($pattern, $v, $matches) )
		{
			if( ($repl = $matches['type']) == 'each' ) $repl = 'foreach';
			$result = '<?php '. $repl .'( '. $matches['expr'] .' ): ?>';
			break;
		}
		
		// skip occured!
		$this->skip_count += 1;
		
		}while( 0 );
		return $result;
	}
	
	protected function process_default_modifiers(&$v)
	{
		$count = null;
		$v2 = preg_replace('~\\|(?!['. self::name_char .'])~u', $this->default_modifier, $v, -1, $count);
		if( $count ) $v = $v2;
		
		return $count;
	}
	
	protected function deduce_variable($v, $matches, $is_static = false)
	{
		$result = $is_static ? (
			$matches['class'] .'::'. ($matches['dot'] == '.' ? '$' : '') . $matches['member']
		) : ('$'. $matches[1]);
		
		// process array elements and class properties
		if( $k = $matches['sub'] ?? false )
		$result .= preg_replace('~\\.(['. self::name_char .']+)~u', "['\\1']", $k);
		
		// process modifiers
		if( $k = $matches['mod'] ?? false )
		{
			$mods = preg_split('~\\|~u', $k, -1, PREG_SPLIT_NO_EMPTY);
			if( count($mods) )
			foreach( $mods as $modifier )
			{
				if( count($parts = explode('.', $modifier)) == 2 )
				list($modifier, $sub) = $parts; else $sub = null;
				
				if( $mod = $this->modifier_find($modifier) )
				$result = $this->modifier_apply($result, $mod, $sub);
			}
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

/*		CHANGELOG
2017-04-05 #3
	added	New modifier syntax introduced
			
			$trix->add_modifiers(['make_url', $callbacks]); // $callbacks should be array of closures or fn names
			$user = ['user_id'=>55];
				{$user|make_url.user_page}
				echo $callbacks['user_page']($user);
			$page = ['page_alias'=>'contacts']; 
				{$page|make_url.static_page}
				echo $callbacks['static_page']($page);
2017-04-04 #2
	also:	Small fix
2017-04-03 #1
	added	static members (class constant, class static variable)
			{&lane.path}
				echo lane::$path;
			{&lane:message}
				echo lane::message;
	also:	Some conversions are now made inside deduce (literals, block openers) now w/o extra fn-call
2017-04-02 #7
	added	else
			elseif
	also:	Redid some conversion to plain tokens (delimiters, block closers, else)
2017-02-18 #6
	added	$trix->skip_count
	also:	renamed to trix // from: late_bee tpl_bee
			re-layer to 3 instead of 2
			some minor fixes and changes
			php 7 is now required // mail me if you wish this for php 5.6 or earlier
			updated information and sample code // see: comment on top
2016-10-03 #1
	added	{if expr}...{/if}
			{foreach expr}...{/foreach}
			{each expr}...{/each} // same as {foreach}
			* as naive realisation *
			no expression check
			no open/close pair check
			no nested sequence check
			no full smarty compatibility
2016-05-18 #3
	added	{* comments *}
	note:	unclosed comments are ignored
			comments will not be saved to resulting file in anyhow
	also:	you should avoid such sequences '{*' or "*}" in strings (like so in JS strings or in attribute values)
	
	added	{ldelim} {ld} {rdelim} {rd}
2016-05-12 #4
	added	{literal}{/literal}
	note:	unclosed literal is ignored
<prior>
	variables $var.key->property|modifier
	empty modifiers in $var| will be replaced by default modifier setting if provided
	modifiers could be chained: $var|mod1|mod2
	
	raw modifiers introduced: [raw_mod => [prefix, ending]]
	$var|raw_mod	(prefix $var ending)
	simple modifiers still allowed: [simp_mod => func_name]
	$var|simp_mod	func_name( $var )
	
	common modifiers introduced (aka static modifiers): add_common_modifiers()
	per-instance modifiers still allowed via: add_modifiers()
*/
