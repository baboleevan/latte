<?php

/**
 * This file is part of the Latte (https://latte.nette.org)
 * Copyright (c) 2008 David Grudl (https://davidgrudl.com)
 */

declare(strict_types=1);

namespace Latte\Macros;

use Latte;
use Latte\CompileException;
use Latte\Helpers;
use Latte\MacroNode;
use Latte\PhpHelpers;
use Latte\PhpWriter;
use Latte\Runtime\SnippetDriver;


/**
 * Block macros.
 */
class BlockMacros extends MacroSet
{
	/** @var string */
	public $snippetAttribute = 'id';

	/** @var \stdClass[] */
	private $stacks = [];

	/** @var \stdClass */
	private $currentStack;

	/** @var string|bool|null */
	private $extends;

	/** @var string[] */
	private $imports;


	public static function install(Latte\Compiler $compiler): void
	{
		$me = new static($compiler);
		$me->addMacro('include', [$me, 'macroInclude']);
		$me->addMacro('includeblock', [$me, 'macroIncludeBlock']); // deprecated
		$me->addMacro('import', [$me, 'macroImport'], null, null, self::ALLOWED_IN_HEAD);
		$me->addMacro('extends', [$me, 'macroExtends'], null, null, self::ALLOWED_IN_HEAD);
		$me->addMacro('layout', [$me, 'macroExtends'], null, null, self::ALLOWED_IN_HEAD);
		$me->addMacro('snippet', [$me, 'macroBlock'], [$me, 'macroBlockEnd']);
		$me->addMacro('block', [$me, 'macroBlock'], [$me, 'macroBlockEnd'], null, self::AUTO_CLOSE);
		$me->addMacro('define', [$me, 'macroBlock'], [$me, 'macroBlockEnd']);
		$me->addMacro('widget', [$me, 'macroWidget'], [$me, 'macroWidgetEnd']);
		$me->addMacro('snippetArea', [$me, 'macroBlock'], [$me, 'macroBlockEnd']);
		$me->addMacro('ifset', [$me, 'macroIfset'], '}');
		$me->addMacro('elseifset', [$me, 'macroIfset']);
	}


	/**
	 * Initializes before template parsing.
	 * @return void
	 */
	public function initialize()
	{
		$this->stacks = [0 => (object) ['blocks' => [], 'types' => []]];
		$this->currentStack = &$this->stacks[0];
		$this->extends = null;
		$this->imports = [];
	}


	/**
	 * Finishes template parsing.
	 */
	public function finalize()
	{
		$compiler = $this->getCompiler();
		$functions = $types = [];
		foreach ($this->stacks as $i => $stack) {
			foreach ($stack->blocks as $name => $code) {
				$fullname = $name . ($i ? '__' . $i : '');
				$compiler->addMethod(
					$functions[$fullname] = $this->generateMethodName($fullname),
					'?>' . $compiler->expandTokens($code) . '<?php',
					'array $_args',
					'void'
				);
				if ($stack->types[$name] !== $compiler->getContentType()) {
					$types[$fullname] = $stack->types[$name];
				}
			}
		}

		if ($functions) {
			$compiler->addConstant('BLOCKS', array_merge_recursive($functions, $types));
		}

		return [
			($this->extends === null ? '' : '$this->parentName = ' . $this->extends . ';') . implode($this->imports),
		];
	}


	/********************* macros ****************d*g**/


	/**
	 * {include block}
	 */
	public function macroInclude(MacroNode $node, PhpWriter $writer)
	{
		$node->replaced = false;
		$destination = $node->tokenizer->fetchWord(); // destination [,] [params]
		if (!$destination || !preg_match('~#|[\w-]+$~DA', $destination)) {
			return false;
		}

		$destination = ltrim($destination, '#');
		$parent = $destination === 'parent';
		if ($destination === 'parent' || $destination === 'this') {
			for (
				$item = $node->parentNode;
				$item && $item->name !== 'block' && !isset($item->data->name);
				$item = $item->parentNode
			);
			if (!$item) {
				throw new CompileException("Cannot include $destination block outside of any block.");
			}
			$destination = $item->data->name;
		}

		$noEscape = Helpers::removeFilter($node->modifiers, 'noescape');
		if (!$noEscape && Helpers::removeFilter($node->modifiers, 'escape')) {
			trigger_error('Macro ' . $node->getNotation() . ' provides auto-escaping, remove |escape.');
		}
		if ($node->modifiers && !$noEscape) {
			$node->modifiers .= '|escape';
		}
		return $writer->write(
			'$this->renderBlock' . ($parent ? 'Parent' : '') . '('
			. (strpos($destination, '$') === false ? PhpHelpers::dump($destination) : $destination)
			. ', %node.array? + '
			. (isset($this->stacks[0]->blocks[$destination]) || $parent ? 'get_defined_vars()' : '$this->params')
			. ($node->modifiers
				? ', function ($s, $type) { $_fi = new LR\FilterInfo($type); return %modifyContent($s); }'
				: ($noEscape || $parent ? '' : ', ' . PhpHelpers::dump(implode($node->context))))
			. ');'
		);
	}


	/**
	 * {includeblock "file"}
	 * @deprecated
	 */
	public function macroIncludeBlock(MacroNode $node, PhpWriter $writer)
	{
		//trigger_error('Macro {includeblock} is deprecated, use similar macro {import}.', E_USER_DEPRECATED);
		$node->replaced = false;
		if ($node->modifiers) {
			throw new CompileException('Modifiers are not allowed in ' . $node->getNotation());
		}
		return $writer->write(
			'ob_start(function () {}); $this->createTemplate(%node.word, %node.array? + get_defined_vars(), "includeblock")->renderToContentType(%var); echo rtrim(ob_get_clean());',
			implode($node->context)
		);
	}


	/**
	 * {import "file"}
	 */
	public function macroImport(MacroNode $node, PhpWriter $writer)
	{
		if ($node->modifiers) {
			throw new CompileException('Modifiers are not allowed in ' . $node->getNotation());
		}
		$destination = $node->tokenizer->fetchWord();
		$this->checkExtraArgs($node);
		$code = $writer->write('$this->createTemplate(%word, $this->params, "import")->render();', $destination);
		if ($this->getCompiler()->isInHead()) {
			$this->imports[] = $code;
		} else {
			return $code;
		}
	}


	/**
	 * {extends none | $var | "file"}
	 */
	public function macroExtends(MacroNode $node, PhpWriter $writer)
	{
		$notation = $node->getNotation();
		if ($node->modifiers) {
			throw new CompileException("Modifiers are not allowed in $notation");
		} elseif ($node->args === '') {
			throw new CompileException("Missing destination in $notation");
		} elseif ($node->parentNode) {
			throw new CompileException("$notation must be placed outside any macro.");
		} elseif ($this->extends !== null) {
			throw new CompileException("Multiple $notation declarations are not allowed.");
		} elseif ($node->args === 'none') {
			$this->extends = 'false';
		} else {
			$this->extends = $writer->write('%node.word%node.args');
		}
		if (!$this->getCompiler()->isInHead()) {
			trigger_error("$notation must be placed in template head.", E_USER_WARNING);
		}
	}


	/**
	 * {block [name]}
	 * {snippet [name]}
	 * {snippetArea [name]}
	 * {define name}
	 */
	public function macroBlock(MacroNode $node, PhpWriter $writer)
	{
		$name = $node->tokenizer->fetchWord();

		if ($node->name === 'block' && $name === null) { // anonymous block
			return $node->modifiers === '' ? '' : 'ob_start(function () {})';

		} elseif ($node->name === 'define' && $node->modifiers) {
			$node->setArgs($node->args . $node->modifiers);
			$node->tokenizer->fetchWord();
		}

		$node->data->name = $name = ltrim((string) $name, '#');
		if ($name == null) {
			if ($node->name === 'define' || $node->name === 'snippetArea') {
				throw new CompileException('Missing block name.');
			}

		} elseif (strpos($name, '$') !== false) { // dynamic block/snippet
			if ($node->name === 'snippet') {
				if ($node->prefix && isset($node->htmlNode->attrs[$this->snippetAttribute])) {
					throw new CompileException("Cannot combine HTML attribute $this->snippetAttribute with n:snippet.");
				}

				for (
					$parent = $node->parentNode;
					$parent && !($parent->name === 'snippet' || $parent->name === 'snippetArea');
					$parent = $parent->parentNode
				);
				if (!$parent) {
					throw new CompileException('Dynamic snippets are allowed only inside static snippet/snippetArea.');
				}
				$parent->data->dynamic = true;
				$node->data->leave = true;
				$node->closingCode = '<?php $this->global->snippetDriver->leave(); ?>';
				$enterCode = '$this->global->snippetDriver->enter(' . $writer->formatWord($name) . ', "' . SnippetDriver::TYPE_DYNAMIC . '");';

				if ($node->prefix) {
					$node->attrCode = $writer->write("<?php echo ' $this->snippetAttribute=\"' . htmlspecialchars(\$this->global->snippetDriver->getHtmlId({$writer->formatWord($name)})) . '\"' ?>");
					return $writer->write($enterCode);
				}
				$node->closingCode .= "\n</div>";
				$this->checkExtraArgs($node);
				return $writer->write("?>\n<div $this->snippetAttribute=\"<?php echo htmlspecialchars(\$this->global->snippetDriver->getHtmlId({$writer->formatWord($name)})) ?>\"><?php " . $enterCode);

			} else {
				$node->data->leave = true;
				$node->data->func = $this->generateMethodName($name);
				$fname = $writer->formatWord($name);
				if ($node->name === 'define') {
					$node->closingCode = '<?php ?>';
				} else {
					if (Helpers::startsWith((string) $node->context[1], Latte\Compiler::CONTEXT_HTML_ATTRIBUTE)) {
						$node->context[1] = '';
						$node->modifiers .= '|escape';
					} elseif ($node->modifiers) {
						$node->modifiers .= '|escape';
					}
					$node->closingCode = $writer->write('<?php $this->renderBlock(%raw, get_defined_vars()'
						. ($node->modifiers ? ', function ($s, $type) { $_fi = new LR\FilterInfo($type); return %modifyContent($s); }' : '') . '); ?>', $fname);
				}
				$blockType = PhpHelpers::dump(implode($node->context));
				$this->checkExtraArgs($node);
				return "\$this->checkBlockContentType($blockType, $fname);"
					. "\$this->blockQueue[$fname][] = [\$this, '{$node->data->func}'];";
			}

		} elseif (!preg_match('#^[a-z]#iD', $name)) {
			throw new CompileException("Block name must start with letter a-z, '$name' given.");
		}

		// static snippet/snippetArea
		if ($node->name === 'snippet' || $node->name === 'snippetArea') {
			if ($node->modifiers) {
				trigger_error('Modifiers are deprecated in ' . $node->getNotation(), E_USER_DEPRECATED);
			}
			if ($node->prefix && isset($node->htmlNode->attrs[$this->snippetAttribute])) {
				throw new CompileException("Cannot combine HTML attribute $this->snippetAttribute with n:snippet.");
			}
			$node->data->name = $name = '_' . $name;
		}

		if (isset($this->currentStack->blocks[$name])) {
			throw new CompileException("Cannot redeclare static {$node->name} '$name'");
		}
		$extendsCheck = ($this->stacks[0]->blocks || count($this->stacks) > 1) ? '' : 'if ($this->getParentName()) { return get_defined_vars();} ';
		$this->currentStack->blocks[$name] = true;

		if (Helpers::removeFilter($node->modifiers, 'escape')) {
			trigger_error('Macro ' . $node->getNotation() . ' provides auto-escaping, remove |escape.');
		}
		if (Helpers::startsWith((string) $node->context[1], Latte\Compiler::CONTEXT_HTML_ATTRIBUTE)) {
			$node->context[1] = '';
			$node->modifiers .= '|escape';
		} elseif ($node->modifiers) {
			$node->modifiers .= '|escape';
		}
		$this->currentStack->types[$name] = implode($node->context);

		$include = '$this->renderBlock(%var, ' . (($node->name === 'snippet' || $node->name === 'snippetArea') ? '$this->params' : 'get_defined_vars()')
			. ($node->modifiers ? ', function ($s, $type) { $_fi = new LR\FilterInfo($type); return %modifyContent($s); }' : '') . ')';

		if ($node->name === 'snippet') {
			if ($node->prefix) {
				if (isset($node->htmlNode->macroAttrs['foreach'])) {
					trigger_error('Combination of n:snippet with n:foreach is invalid, use n:inner-foreach.', E_USER_WARNING);
				}
				$node->attrCode = $writer->write("<?php echo ' $this->snippetAttribute=\"' . htmlspecialchars(\$this->global->snippetDriver->getHtmlId(%var)) . '\"' ?>", (string) substr($name, 1));
				return $writer->write($include, $name);
			}
			$this->checkExtraArgs($node);
			return $writer->write("?>\n<div $this->snippetAttribute=\"<?php echo htmlspecialchars(\$this->global->snippetDriver->getHtmlId(%var)) ?>\"><?php $include ?>\n</div><?php ",
				(string) substr($name, 1), $name
			);

		} elseif ($node->name === 'define') {
			$tokens = $node->tokenizer;
			$args = [];
			while ($tokens->isNext()) {
				if ($tokens->nextToken($tokens::T_SYMBOL, '?', 'null', '\\')) { // type
					$tokens->nextAll($tokens::T_SYMBOL, '\\', '|', '[', ']', 'null');
				}
				$args[] = $tokens->consumeValue($tokens::T_VARIABLE);
				if ($tokens->isNext()) {
					$tokens->consumeValue(',');
				}
			}
			if ($args) {
				$node->data->args = '[' . implode(', ', $args) . '] = $_args + [' . str_repeat('null, ', count($args)) . '];';
			}
			return $extendsCheck;

		} else { // block, snippetArea
			$this->checkExtraArgs($node);
			return $writer->write($extendsCheck . $include, $name);
		}
	}


	/**
	 * {/block}
	 * {/snippet}
	 * {/snippetArea}
	 * {/define}
	 */
	public function macroBlockEnd(MacroNode $node, PhpWriter $writer)
	{
		if (isset($node->data->name)) { // block, snippet, define
			if ($asInner = $node->name === 'snippet' && $node->prefix === MacroNode::PREFIX_NONE) {
				$node->content = $node->innerContent;
			}

			if (($node->name === 'snippet' || $node->name === 'snippetArea') && strpos($node->data->name, '$') === false) {
				$type = $node->name === 'snippet' ? SnippetDriver::TYPE_STATIC : SnippetDriver::TYPE_AREA;
				$node->content = '<?php $this->global->snippetDriver->enter('
					. $writer->formatWord(substr($node->data->name, 1))
					. ', "' . $type . '"); ?>'
					. preg_replace('#(?<=\n)[ \t]+$#D', '', $node->content) . '<?php $this->global->snippetDriver->leave(); ?>';
			}
			if (empty($node->data->leave)) {
				if (preg_match('#\$|n:#', $node->content)) {
					$node->content = '<?php ' . (isset($node->data->args) ? 'extract($this->params); ' . $node->data->args : 'extract($_args);') . ' ?>'
						. $node->content;
				}
				$this->currentStack->blocks[$node->data->name] = $tmp = preg_replace('#^\n+|(?<=\n)[ \t]+$#D', '', $node->content);
				$node->content = substr_replace($node->content, $node->openingCode . "\n", strspn($node->content, "\n"), strlen($tmp));
				$node->openingCode = '<?php ?>';

			} elseif (isset($node->data->func)) {
				$node->content = rtrim($node->content, " \t");
				$this->getCompiler()->addMethod(
					$node->data->func,
					$this->getCompiler()->expandTokens("extract(\$_args);\n?>$node->content<?php"),
					'array $_args',
					'void'
				);
				$node->content = '';
			}

			if ($asInner) { // n:snippet -> n:inner-snippet
				$node->innerContent = $node->openingCode . $node->content . $node->closingCode;
				$node->closingCode = $node->openingCode = '<?php ?>';
			}
			return ' '; // consume next new line

		} elseif ($node->modifiers) { // anonymous block with modifier
			$node->modifiers .= '|escape';
			return $writer->write('$_fi = new LR\FilterInfo(%var); echo %modifyContent(ob_get_clean());', $node->context[0]);
		}
	}


	/**
	 * {widget "file"}
	 */
	public function macroWidget(MacroNode $node, PhpWriter $writer)
	{
		if ($node->modifiers) {
			throw new CompileException('Modifiers are not allowed in ' . $node->getNotation());
		}

		$key = count($this->stacks);
		$this->stacks[] = $this->currentStack = (object) ['blocks' => [], 'types' => [], 'parent' => $this->currentStack];

		return $writer->write(
			'$this->createTemplate(%node.word, %node.array, "widget", %var)->renderToContentType(%var); if (false) {',
			$key,
			implode($node->context)
		);
	}


	/**
	 * {/widget}
	 */
	public function macroWidgetEnd(MacroNode $node, PhpWriter $writer)
	{
		$this->currentStack = $this->currentStack->parent;
		return $writer->write('}');
	}


	/**
	 * {ifset block}
	 * {elseifset block}
	 */
	public function macroIfset(MacroNode $node, PhpWriter $writer)
	{
		if ($node->modifiers) {
			throw new CompileException('Modifiers are not allowed in ' . $node->getNotation());
		}
		if (!preg_match('~#|[\w-]+$~DA', $node->args)) {
			return false;
		}
		$list = [];
		while (($name = $node->tokenizer->fetchWord()) !== null) {
			$list[] = preg_match('~#|[\w-]+$~DA', $name)
				? '$this->blockQueue["' . ltrim($name, '#') . '"]'
				: $writer->formatArgs(new Latte\MacroTokens($name));
		}
		return ($node->name === 'elseifset' ? '} else' : '')
			. 'if (isset(' . implode(', ', $list) . ')) {';
	}


	private function generateMethodName(string $blockName): string
	{
		$clean = trim(preg_replace('#\W+#', '_', $blockName), '_');
		$name = 'block' . ucfirst($clean);
		$methods = array_keys($this->getCompiler()->getMethods());
		if (!$clean || in_array(strtolower($name), array_map('strtolower', $methods), true)) {
			$name .= '_' . substr(md5($blockName), 0, 5);
		}
		return $name;
	}
}
