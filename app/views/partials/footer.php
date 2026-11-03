<?php

declare(strict_types=1);

?>
<?php if (auth_is_logged_in()): ?>
                </div>
            </main>
<?php else: ?>
    </main>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/app.js?v=20260315.4"></script>
<?php if (auth_is_logged_in()): ?>
        </div>
    </div>
<?php endif; ?>
</body>
</html>
