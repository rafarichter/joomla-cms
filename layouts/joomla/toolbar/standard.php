<?php
/**
 * @package     Joomla.Site
 * @subpackage  Layout
 *
 * @copyright   Copyright (C) 2005 - 2017 Open Source Matters, Inc. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('JPATH_BASE') or die;

use Joomla\CMS\HTML\HTMLHelper;

HTMLHelper::_('behavior.core');

/**
 * @var  int     $id
 * @var  string  $doTask
 * @var  string  $class
 * @var  string  $text
 * @var  string  $btnClass
 * @var  string  $group
 * @var  string  $tagName
 * @var  string  $htmlAttributes
 */
extract($displayData, EXTR_OVERWRITE);

$tagName = $tagName ?? 'button';

// TODO: RE-ADD button-{name} class.
?>

<?php if (!empty($group)) : ?>
<a<?php echo $id ?? ''; ?> href="#" onclick="<?php echo $doTask ?? ''; ?>" class="dropdown-item">
	<span class="<?php echo trim($class ?? ''); ?>"></span>
	<?php echo $text ?? ''; ?>
</a>
<?php else : ?>
<<?php echo $tagName; ?><?php echo $id ?? ''; ?>
	onclick="<?php echo $doTask ?? ''; ?>"
	class="<?php echo $btnClass ?? ''; ?>"
	<?php echo $htmlAttributes ?? ''; ?>
	>
	<span class="<?php echo trim($class ?? ''); ?>" aria-hidden="true"></span>
	<?php echo $text ?? ''; ?>
</<?php echo $tagName; ?>>
<?php endif; ?>
