<?php

namespace App\Exceptions;

/**
 * Thrown when deleting a category that still has live child categories in
 * the ERS asset hierarchy. Extends ItemStillHasChildren so any existing
 * generic handler still refuses the delete.
 */
class CategoryStillHasChildCategories extends ItemStillHasChildren {}
