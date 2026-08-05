<!-- FOOTER –––––––––––––––––––––––––––––––––––––––
 The columns come from pages/footer/footer1.html,
 footer2.html and so on. Add or delete files there
 to change the number of columns; the framework
 counts them automatically.
–––––––––––––––––––––––––––––––––––––––––––––––– -->
<footer class="footercontainer">
	<div class="footercontent">
		<?php include 'required/footercolumns.php'; ?>
	</div>

	<div class="footerbottom">
		<p class="flush">&copy; <?php echo date("Y") . " " . $WebsiteTitle; ?></p>
	</div>
</footer>

<?php echo $pluginCalledBelowContent; ?>
<?php include 'navigation-options.php' ?>
</body>
</html>
