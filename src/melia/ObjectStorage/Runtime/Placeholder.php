<?php

namespace melia\ObjectStorage\Runtime;

/**
 * Placeholder class used for dynamic class aliasing when a historic
 * class name is missing at load time. This keeps old data loadable
 * without binding to stdClass or anonymous classes.
 */
final class Placeholder {}