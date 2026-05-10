    </main>
  </div><!-- /.flex-1 -->
</div><!-- /.flex -->

<script>
  // Initialize Lucide icons
  lucide.createIcons();

  function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebar-overlay');
    const hidden  = sidebar.classList.toggle('-translate-x-full');
    overlay.classList.toggle('hidden', hidden);
  }
</script>
</body>
</html>
