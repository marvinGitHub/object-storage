<?php

namespace melia\ObjectStorage\Exception;

/**
 * The metadata of an object has no checksum (e.g. written by other tools or by hand), so its data can't be verified.
 */
class MissingChecksumException extends InvalidFileFormatException
{

}
