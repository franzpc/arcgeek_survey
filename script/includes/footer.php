    </div> <!-- Close container from header -->

    <!-- Footer -->
    <footer class="bg-dark text-light py-4 mt-5">
        <div class="container">
            <div class="row">
                <div class="col-md-4 mb-3">
                    <h5>
                        <?php if (!empty($site_config['logo_url'])): ?>
                            <img src="<?php echo htmlspecialchars($site_config['logo_url']); ?>" alt="Logo" style="max-height: 30px;">
                        <?php endif; ?>
                        <?php echo htmlspecialchars($site_config['name']); ?>
                    </h5>
                    <p class="small text-muted">
                        Professional georeferenced surveys with QGIS integration
                    </p>
                </div>

                <div class="col-md-4 mb-3">
                    <h6>Quick Links</h6>
                    <ul class="list-unstyled small">
                        <?php if ($is_logged_in): ?>
                            <li><a href="/survey/dashboard/" class="text-light text-decoration-none"><i class="fas fa-home"></i> Dashboard</a></li>
                            <li><a href="/survey/dashboard/forms.php" class="text-light text-decoration-none"><i class="fas fa-wpforms"></i> My Forms</a></li>
                            <li><a href="/survey/dashboard/settings.php" class="text-light text-decoration-none"><i class="fas fa-cog"></i> Settings</a></li>
                        <?php else: ?>
                            <li><a href="/survey/" class="text-light text-decoration-none"><i class="fas fa-home"></i> Home</a></li>
                            <li><a href="/survey/auth/register.php" class="text-light text-decoration-none"><i class="fas fa-user-plus"></i> Register</a></li>
                            <li><a href="/survey/auth/login.php" class="text-light text-decoration-none"><i class="fas fa-sign-in-alt"></i> Login</a></li>
                        <?php endif; ?>
                        <li><a href="https://github.com/franzpc/arcgeek_survey" target="_blank" class="text-light text-decoration-none"><i class="fab fa-github"></i> GitHub</a></li>
                    </ul>
                </div>

                <div class="col-md-4 mb-3">
                    <h6>Contact</h6>
                    <ul class="list-unstyled small">
                        <li>
                            <i class="fas fa-envelope"></i>
                            <a href="mailto:<?php echo htmlspecialchars($site_config['support_email']); ?>" class="text-light text-decoration-none">
                                <?php echo htmlspecialchars($site_config['support_email']); ?>
                            </a>
                        </li>
                        <li><i class="fas fa-globe"></i> <a href="https://acolita.com/survey" target="_blank" class="text-light text-decoration-none">acolita.com/survey</a></li>
                        <li class="mt-3">
                            <a href="https://github.com/franzpc/arcgeek_survey" target="_blank" class="text-light me-3">
                                <i class="fab fa-github fa-lg"></i>
                            </a>
                            <a href="https://acolita.com/survey" target="_blank" class="text-light">
                                <i class="fas fa-globe fa-lg"></i>
                            </a>
                        </li>
                    </ul>
                </div>
            </div>

            <hr class="border-secondary">

            <div class="row">
                <div class="col-md-6">
                    <small class="text-muted">
                        <?php echo htmlspecialchars($site_config['footer_text']); ?>
                    </small>
                </div>
                <div class="col-md-6 text-md-end">
                    <small class="text-muted">
                        <i class="fas fa-code"></i> Built with <i class="fas fa-heart text-danger"></i> for the GIS community
                    </small>
                </div>
            </div>
        </div>
    </footer>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <?php if (isset($additional_footer_scripts)) echo $additional_footer_scripts; ?>
</body>
</html>
