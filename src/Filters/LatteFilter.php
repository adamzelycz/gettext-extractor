<?php
declare(strict_types=1);
/**
 * @copyright Copyright (c) 2009 Karel Klíma
 * @copyright Copyright (c) 2010 Ondřej Vodáček
 * @license New BSD License
 */

namespace Vodacek\GettextExtractor\Filters;

use Nette\Utils\FileSystem;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Expression;
use Vodacek\GettextExtractor\Extractor;
use PhpParser;
use Latte\Compiler\TemplateLexer;
use Latte\Compiler\Token;

class LatteFilter extends AFilter implements IFilter {

	public function __construct() {
		$this->addFunction('_');
		$this->addFunction('!_');
		$this->addFunction('_n', 1, 2);
		$this->addFunction('!_n', 1, 2);
		$this->addFunction('_p', 2, null, 1);
		$this->addFunction('!_p', 2, null, 1);
		$this->addFunction('_np', 2, 3, 1);
		$this->addFunction('!_np', 2, 3, 1);
	}

	/** @return mixed[] */
	public function extract(string $file): array {
		$data = array();

		$functions = array_keys($this->functions);
		usort($functions, static function(string $a, string $b) {
			return strlen($b) <=> strlen($a);
		});

		$phpParser = (new PhpParser\ParserFactory())->create(PhpParser\ParserFactory::PREFER_PHP7);
		foreach ($this->findTags(FileSystem::read($file)) as [$content, $line]) {
			$name = $this->findMacroName('{' . $content, $functions);
			if ($name === null) {
				continue;
			}
			$value = substr($content, strlen($name));
			$stmts = $phpParser->parse("<?php\nf($value);");

			if ($stmts === null) {
				continue;
			}
			if ($stmts[0] instanceof Expression && $stmts[0]->expr instanceof FuncCall) {
				foreach ($this->functions[$name] as $definition) {
					$message = $this->processFunction($definition, $stmts[0]->expr);
					if ($message !== []) {
						$message[Extractor::LINE] = $line;
						$data[] = $message;
					}
				}
			}
		}
		return $data;
	}

	/**
	 * Latte tags as [content between the delimiters without modifiers, line]. The lexer is driven without
	 * the template parser, so tags unknown to Latte (custom macros) do not fail.
	 * @return array{string, int}[]
	 */
	private function findTags(string $template): array {
		$lexer = new TemplateLexer();
		$tags = array();
		$content = null;
		$line = 0;
		$depth = 0;
		$modifiers = false;
		foreach ($lexer->tokenize($template) as $token) {
			if ($token->is(Token::Latte_TagOpen)) {
				$content = '';
				$line = $token->position->line;
				$depth = 0;
				$modifiers = false;
				$lexer->pushState(TemplateLexer::StateLatteTag);

			} elseif ($token->is(Token::Latte_TagClose) && $content !== null) {
				$lexer->popState();
				$tags[] = [$content, $line];
				if (preg_match('#^syntax\s+(\w+)#', $content, $m) === 1) {
					$lexer->setSyntax($m[1], 'syntax');
				} elseif ($content === '/syntax') {
					$lexer->popSyntax();
				}
				$content = null;

			} elseif ($token->is(Token::Latte_CommentOpen)) {
				$lexer->pushState(TemplateLexer::StateLatteComment);

			} elseif ($token->is(Token::Latte_CommentClose)) {
				$lexer->popState();

			} elseif ($content !== null && !$modifiers) {
				if ($token->is('(', '[', '{')) {
					$depth++;
				} elseif ($token->is(')', ']', '}')) {
					$depth--;
				} elseif ($depth === 0 && $token->is('|')) {
					$modifiers = true;
					continue;
				}
				$content .= $token->text;
			}
		}
		return $tags;
	}

	/** @return mixed[] */
	private function processFunction(array $definition, FuncCall $node): array {
		$message = [];
		foreach ($definition as $type => $position) {
			if (!isset($node->args[$position - 1])) {
				return [];
			}
			$arg = $node->args[$position - 1]->value;
			if ($arg instanceof String_) {
				$message[$type] = $arg->value;
			} else {
				return [];
			}
		}
		return $message;
	}

	/** @param mixed[] $functions */
	private function findMacroName(string $text, array $functions): ?string {
		foreach ($functions as $function) {
			if (strpos($text, '{'.$function) === 0) {
				return $function;
			}
		}
		return null;
	}
}
