<?php

namespace KdrDev\Dissect\Changes;

use RuntimeException;

/** A snapshot that is missing, is not one, or is in another format. The message says which, and what to run. */
final class UnreadableSnapshot extends RuntimeException {}
