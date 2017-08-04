<?php
/**
 * WordPress Coding Standard.
 *
 * @package WPCS\WordPressCodingStandards
 * @link    https://github.com/WordPress-Coding-Standards/WordPress-Coding-Standards
 * @license https://opensource.org/licenses/MIT MIT
 */

namespace WordPress\Sniffs\XSS;

use WordPress\Sniff;
use PHP_CodeSniffer_Tokens as Tokens;

/**
 * Verifies that all outputted strings are escaped.
 *
 * @link    http://codex.wordpress.org/Data_Validation Data Validation on WordPress Codex
 *
 * @package WPCS\WordPressCodingStandards
 *
 * @since   2013-06-11
 * @since   0.4.0  This class now extends WordPress_Sniff.
 * @since   0.5.0  The various function list properties which used to be contained in this class
 *                 have been moved to the WordPress_Sniff parent class.
 * @since   0.12.0 This sniff will now also check for output escaping when using shorthand
 *                 echo tags `<?=`.
 * @since   0.13.0 Class name changed: this class is now namespaced.
 */
class EscapeOutputSniff extends Sniff {

	/**
	 * Custom list of functions which escape values for output.
	 *
	 * @since 0.5.0
	 *
	 * @var string|string[]
	 */
	public $customEscapingFunctions = array();

	/**
	 * Custom list of functions whose return values are pre-escaped for output.
	 *
	 * @since 0.3.0
	 *
	 * @var string|string[]
	 */
	public $customAutoEscapedFunctions = array();

	/**
	 * Custom list of functions which escape values for output.
	 *
	 * @since      0.3.0
	 * @deprecated 0.5.0 Use $customEscapingFunctions instead.
	 * @see        \WordPress\Sniffs\XSS\EscapeOutputSniff::$customEscapingFunctions
	 *
	 * @var string|string[]
	 */
	public $customSanitizingFunctions = array();

	/**
	 * Custom list of functions which print output incorporating the passed values.
	 *
	 * @since 0.4.0
	 *
	 * @var string|string[]
	 */
	public $customPrintingFunctions = array();

	/**
	 * Printing functions that incorporate unsafe values.
	 *
	 * @since 0.4.0
	 * @since 0.11.0 Changed from public static to protected non-static.
	 *
	 * @var array
	 */
	protected $unsafePrintingFunctions = array(
		'_e'  => 'esc_html_e() or esc_attr_e()',
		'_ex' => 'esc_html_ex() or esc_attr_ex()',
	);

	/**
	 * Cache of previously added custom functions.
	 *
	 * Prevents having to do the same merges over and over again.
	 *
	 * @since 0.4.0
	 * @since 0.11.0 - Changed from public static to protected non-static.
	 *               - Changed the format from simple bool to array.
	 *
	 * @var array
	 */
	protected $addedCustomFunctions = array(
		'escape'     => null,
		'autoescape' => null,
		'sanitize'   => null,
		'print'      => null,
	);

	/**
	 * List of names of the tokens representing PHP magic constants.
	 *
	 * @since 0.10.0
	 *
	 * @var array
	 */
	private $magic_constant_tokens = array(
		'T_CLASS_C'  => true, // __CLASS__
		'T_DIR'      => true, // __DIR__
		'T_FILE'     => true, // __FILE__
		'T_FUNC_C'   => true, // __FUNCTION__
		'T_LINE'     => true, // __LINE__
		'T_METHOD_C' => true, // __METHOD__
		'T_NS_C'     => true, // __NAMESPACE__
		'T_TRAIT_C'  => true, // __TRAIT__
	);

	/**
	 * List of names of the cast tokens which can be considered as a safe escaping method.
	 *
	 * @since 0.12.0
	 *
	 * @var array
	 */
	private $safe_cast_tokens = array(
		'T_INT_CAST'    => true, // (int)
		'T_DOUBLE_CAST' => true, // (float)
		'T_BOOL_CAST'   => true, // (bool)
		'T_UNSET_CAST'  => true, // (unset)
	);

	/**
	 * List of tokens which can be considered as a safe when directly part of the output.
	 *
	 * @since 0.12.0
	 *
	 * @var array
	 */
	private $safe_components = array(
		'T_CONSTANT_ENCAPSED_STRING' => true,
		'T_LNUMBER'                  => true,
		'T_MINUS'                    => true,
		'T_PLUS'                     => true,
		'T_MULTIPLY'                 => true,
		'T_DIVIDE'                   => true,
		'T_MODULUS'                  => true,
		'T_TRUE'                     => true,
		'T_FALSE'                    => true,
		'T_NULL'                     => true,
		'T_DNUMBER'                  => true,
		'T_START_NOWDOC'             => true,
		'T_NOWDOC'                   => true,
		'T_END_NOWDOC'               => true,
	);

	/**
	 * Returns an array of tokens this test wants to listen for.
	 *
	 * @return array
	 */
	public function register() {

		$tokens = array(
			T_ECHO,
			T_PRINT,
			T_EXIT,
			T_STRING,
			T_OPEN_TAG_WITH_ECHO,
		);

		/*
		 * Check whether short open echo tags are disabled and if so, register the
		 * T_INLINE_HTML token which is how short open tags are being handled in that case.
		 *
		 * In PHP < 5.4, support for short open echo tags depended on whether the
		 * `short_open_tag` ini directive was set to `true`.
		 * For PHP >= 5.4, the `short_open_tag` no longer affects the short open
		 * echo tags and these are now always enabled.
		 */
		if ( PHP_VERSION_ID < 50400 && false === (bool) ini_get( 'short_open_tag' ) ) {
			$tokens[] = T_INLINE_HTML;
		}
		return $tokens;
	}

	/**
	 * Processes this test, when one of its tokens is encountered.
	 *
	 * @param int $stackPtr The position of the current token in the stack.
	 *
	 * @return int|void Integer stack pointer to skip forward or void to continue
	 *                  normal file processing.
	 */
	public function process_token( $stackPtr ) {

		$this->mergeFunctionLists();

		$function = $this->tokens[ $stackPtr ]['content'];

		// Find the opening parenthesis (if present; T_ECHO might not have it).
		$open_paren = $this->phpcsFile->findNext( Tokens::$emptyTokens, ( $stackPtr + 1 ), null, true );

		// If function, not T_ECHO nor T_PRINT.
		if ( T_STRING === $this->tokens[ $stackPtr ]['code'] ) {
			// Skip if it is a function but is not of the printing functions.
			if ( ! isset( $this->printingFunctions[ $this->tokens[ $stackPtr ]['content'] ] ) ) {
				return;
			}

			if ( isset( $this->tokens[ $open_paren ]['parenthesis_closer'] ) ) {
				$end_of_statement = $this->tokens[ $open_paren ]['parenthesis_closer'];
			}

			// These functions only need to have the first argument escaped.
			if ( in_array( $function, array( 'trigger_error', 'user_error' ), true ) ) {
				$end_of_statement = $this->phpcsFile->findEndOfStatement( $open_paren + 1 );
			}
		} elseif ( T_INLINE_HTML === $this->tokens[ $stackPtr ]['code'] ) {
			// Skip if no PHP short_open_tag is found in the string.
			if ( false === strpos( $this->tokens[ $stackPtr ]['content'], '<?=' ) ) {
				return;
			}

			// Report on what is very likely a PHP short open echo tag outputting a variable.
			if ( preg_match( '`\<\?\=[\s]*(\$[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*(?:(?:->\S+|\[[^\]]+\]))*)[\s]*;?[\s]*\?\>`', $this->tokens[ $stackPtr ]['content'], $matches ) > 0 ) {
				$this->phpcsFile->addError(
					'Expected next thing to be an escaping function, not %s.',
					$stackPtr,
					'OutputNotEscaped',
					array( $matches[1] )
				);
				return;
			}

			return;
		}

		// Checking for the ignore comment, ex: //xss ok.
		if ( $this->has_whitelist_comment( 'xss', $stackPtr ) ) {
			return;
		}

		if ( isset( $end_of_statement, $this->unsafePrintingFunctions[ $function ] ) ) {
			$error = $this->phpcsFile->addError(
				"Expected next thing to be an escaping function (like %s), not '%s'",
				$stackPtr,
				'UnsafePrintingFunction',
				array( $this->unsafePrintingFunctions[ $function ], $function )
			);

			// If the error was reported, don't bother checking the function's arguments.
			if ( $error ) {
				return $end_of_statement;
			}
		}

		$ternary = false;

		// This is already determined if this is a function and not T_ECHO.
		if ( ! isset( $end_of_statement ) ) {

			$end_of_statement = $this->phpcsFile->findNext( array( T_SEMICOLON, T_CLOSE_TAG ), $stackPtr );
			$last_token       = $this->phpcsFile->findPrevious( Tokens::$emptyTokens, ( $end_of_statement - 1 ), null, true );

			// Check for the ternary operator. We only need to do this here if this
			// echo is lacking parenthesis. Otherwise it will be handled below.
			if ( T_OPEN_PARENTHESIS !== $this->tokens[ $open_paren ]['code'] || T_CLOSE_PARENTHESIS !== $this->tokens[ $last_token ]['code'] ) {

				$ternary = $this->phpcsFile->findNext( T_INLINE_THEN, $stackPtr, $end_of_statement );

				// If there is a ternary skip over the part before the ?. However, if
				// the ternary is within parentheses, it will be handled in the loop.
				if ( false !== $ternary && empty( $this->tokens[ $ternary ]['nested_parenthesis'] ) ) {
					$stackPtr = $ternary;
				}
			}
		}

		// Ignore the function itself.
		$stackPtr++;

		$in_cast = false;

		// Looping through echo'd components.
		$watch = true;
		for ( $i = $stackPtr; $i < $end_of_statement; $i++ ) {

			// Ignore whitespaces and comments.
			if ( isset( Tokens::$emptyTokens[ $this->tokens[ $i ]['code'] ] ) ) {
				continue;
			}

			if ( T_OPEN_PARENTHESIS === $this->tokens[ $i ]['code'] ) {

				if ( ! isset( $this->tokens[ $i ]['parenthesis_closer'] ) ) {
					// Live coding or parse error.
					break;
				}

				if ( $in_cast ) {

					// Skip to the end of a function call if it has been casted to a safe value.
					$i       = $this->tokens[ $i ]['parenthesis_closer'];
					$in_cast = false;

				} else {

					// Skip over the condition part of a ternary (i.e., to after the ?).
					$ternary = $this->phpcsFile->findNext( T_INLINE_THEN, $i, $this->tokens[ $i ]['parenthesis_closer'] );

					if ( false !== $ternary ) {

						$next_paren = $this->phpcsFile->findNext( T_OPEN_PARENTHESIS, ( $i + 1 ), $this->tokens[ $i ]['parenthesis_closer'] );

						// We only do it if the ternary isn't within a subset of parentheses.
						if ( false === $next_paren || ( isset( $this->tokens[ $next_paren ]['parenthesis_closer'] ) && $ternary > $this->tokens[ $next_paren ]['parenthesis_closer'] ) ) {
							$i = $ternary;
						}
					}
				}

				continue;
			}

			// Handle arrays for those functions that accept them.
			if ( T_ARRAY === $this->tokens[ $i ]['code'] ) {
				$i++; // Skip the opening parenthesis.
				continue;
			}

			if ( in_array( $this->tokens[ $i ]['code'], array( T_DOUBLE_ARROW, T_CLOSE_PARENTHESIS ), true ) ) {
				continue;
			}

			// Handle magic constants for debug functions.
			if ( isset( $this->magic_constant_tokens[ $this->tokens[ $i ]['type'] ] ) ) {
				continue;
			}

			// Wake up on concatenation characters, another part to check.
			if ( T_STRING_CONCAT === $this->tokens[ $i ]['code'] ) {
				$watch = true;
				continue;
			}

			// Wake up after a ternary else (:).
			if ( $ternary && T_INLINE_ELSE === $this->tokens[ $i ]['code'] ) {
				$watch = true;
				continue;
			}

			// Wake up for commas.
			if ( T_COMMA === $this->tokens[ $i ]['code'] ) {
				$in_cast = false;
				$watch   = true;
				continue;
			}

			if ( false === $watch ) {
				continue;
			}

			// Allow T_CONSTANT_ENCAPSED_STRING eg: echo 'Some String';
			// Also T_LNUMBER, e.g.: echo 45; exit -1; and booleans.
			if ( isset( $this->safe_components[ $this->tokens[ $i ]['type'] ] ) ) {
				continue;
			}

			$watch = false;

			// Allow int/double/bool casted variables.
			if ( isset( $this->safe_cast_tokens[ $this->tokens[ $i ]['type'] ] ) ) {
				$in_cast = true;
				continue;
			}

			// Now check that next token is a function call.
			if ( T_STRING === $this->tokens[ $i ]['code'] ) {

				$ptr                    = $i;
				$functionName           = $this->tokens[ $i ]['content'];
				$function_opener        = $this->phpcsFile->findNext( T_OPEN_PARENTHESIS, ( $i + 1 ), null, false, null, true );
				$is_formatting_function = isset( $this->formattingFunctions[ $functionName ] );

				if ( false !== $function_opener ) {

					if ( 'array_map' === $functionName ) {

						// Get the first parameter (name of function being used on the array).
						$mapped_function = $this->phpcsFile->findNext(
							Tokens::$emptyTokens,
							( $function_opener + 1 ),
							$this->tokens[ $function_opener ]['parenthesis_closer'],
							true
						);

						// If we're able to resolve the function name, do so.
						if ( $mapped_function && T_CONSTANT_ENCAPSED_STRING === $this->tokens[ $mapped_function ]['code'] ) {
							$functionName = $this->strip_quotes( $this->tokens[ $mapped_function ]['content'] );
							$ptr = $mapped_function;
						}
					}

					// Skip pointer to after the function.
					// If this is a formatting function we just skip over the opening
					// parenthesis. Otherwise we skip all the way to the closing.
					if ( $is_formatting_function ) {
						$i     = ( $function_opener + 1 );
						$watch = true;
					} else {
						if ( isset( $this->tokens[ $function_opener ]['parenthesis_closer'] ) ) {
							$i = $this->tokens[ $function_opener ]['parenthesis_closer'];
						} else {
							// Live coding or parse error.
							break;
						}
					}
				}

				// If this is a safe function, we don't flag it.
				if (
					$is_formatting_function
					|| isset( $this->autoEscapedFunctions[ $functionName ] )
					|| isset( $this->escapingFunctions[ $functionName ] )
				) {
					continue;
				}

				$content = $functionName;

			} else {
				$content = $this->tokens[ $i ]['content'];
				$ptr     = $i;
			}

			$this->phpcsFile->addError(
				"Expected next thing to be an escaping function (see Codex for 'Data Validation'), not '%s'",
				$ptr,
				'OutputNotEscaped',
				$content
			);
		}

		return $end_of_statement;

	} // End process_token().

	/**
	 * Merge custom functions provided via a custom ruleset with the defaults, if we haven't already.
	 *
	 * @since 0.11.0 Split out from the `process()` method.
	 *
	 * @return void
	 */
	protected function mergeFunctionLists() {
		if ( $this->customEscapingFunctions !== $this->addedCustomFunctions['escape']
			|| $this->customSanitizingFunctions !== $this->addedCustomFunctions['sanitize']
		) {
			$customEscapeFunctions = $this->merge_custom_array( $this->customEscapingFunctions, array(), false );

			if ( ! empty( $this->customSanitizingFunctions ) ) {
				$customEscapeFunctions = $this->merge_custom_array(
					$this->customSanitizingFunctions,
					$customEscapeFunctions,
					false
				);

				$this->phpcsFile->addWarning(
					'The customSanitizingFunctions property is deprecated in favor of customEscapingFunctions.',
					0,
					'DeprecatedCustomSanitizingFunctions'
				);
			}

			$this->escapingFunctions = $this->merge_custom_array(
				$customEscapeFunctions,
				$this->escapingFunctions
			);
			$this->addedCustomFunctions['escape']   = $this->customEscapingFunctions;
			$this->addedCustomFunctions['sanitize'] = $this->customSanitizingFunctions;
		}

		if ( $this->customAutoEscapedFunctions !== $this->addedCustomFunctions['autoescape'] ) {
			$this->autoEscapedFunctions = $this->merge_custom_array(
				$this->customAutoEscapedFunctions,
				$this->autoEscapedFunctions
			);
			$this->addedCustomFunctions['autoescape'] = $this->customAutoEscapedFunctions;
		}

		if ( $this->customPrintingFunctions !== $this->addedCustomFunctions['print'] ) {

			$this->printingFunctions = $this->merge_custom_array(
				$this->customPrintingFunctions,
				$this->printingFunctions
			);
			$this->addedCustomFunctions['print'] = $this->customPrintingFunctions;
		}
	}

} // End class.
