<?php

namespace Slipstream\Release\Core;

/**
 * An envelope that does not verify, with the one reason it does not.
 */
final class SignatureException extends \RuntimeException {}
