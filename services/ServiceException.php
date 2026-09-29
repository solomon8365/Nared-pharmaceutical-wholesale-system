<?php
/**
 * Central service-layer exception.
 *
 * Throw this from any service when a business rule fails. The calling page
 * catches it and renders a consistent error message.
 */
class ServiceException extends RuntimeException
{
}
