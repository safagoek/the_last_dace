<footer class="py-4 mt-5 footer-simple">
    <div class="container text-center">
        <div class="social-links mb-3">
            <a href="https://github.com/safagoek" class="social-link mx-2" target="_blank">
                <i class="bi bi-github fs-4"></i>
            </a>
            <a href="https://linkedin.com" class="social-link mx-2" target="_blank">
                <i class="bi bi-linkedin fs-4"></i>
            </a>
        </div>
        <p class="mb-0 copyright">&copy; <?= date('Y') ?> | Bu bir DEU YBS bitirme projesidir.</p>
    </div>
</footer>

<style>
    .footer-simple {
        background-color: white;
        border-top: 1px solid #eaeaea;
        color: #6c757d;
    }
    
    .social-link {
        color: #6c757d;
        transition: color 0.2s;
    }
    
    .social-link:hover {
        color: #4361ee;
    }
    
    .copyright {
        font-size: 14px;
    }
</style>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Scroll progress bar
    window.onscroll = function() {
        const winScroll = document.body.scrollTop || document.documentElement.scrollTop;
        const height = document.documentElement.scrollHeight - document.documentElement.clientHeight;
        const scrolled = (winScroll / height) * 100;
        document.getElementById("scrollProgress").style.width = scrolled + "%";
    };
</script>
<script src="/assets/js/script.js"></script>
</body>
</html>