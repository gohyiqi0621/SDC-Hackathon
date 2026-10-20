<?php
/** products/_tabs.php - switch between the product list and categories. */
$current = page_key();
$onCategories = nav_is_active('categories', $current);
?>
<nav class="page-tabs" aria-label="Product & Service sections">
    <a class="page-tab<?= !$onCategories ? ' active' : '' ?>" href="<?= e(url('products')) ?>"<?= !$onCategories ? ' aria-current="page"' : '' ?>>
        <i class="fa-solid fa-box-open"></i> Products &amp; Services
    </a>
    <a class="page-tab<?= $onCategories ? ' active' : '' ?>" href="<?= e(url('categories')) ?>"<?= $onCategories ? ' aria-current="page"' : '' ?>>
        <i class="fa-solid fa-tags"></i> Categories
    </a>
</nav>
