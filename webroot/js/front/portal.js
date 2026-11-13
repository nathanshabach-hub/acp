(function ($) {
    "use strict";

    $(function () {
        $("[data-supervisor-consent]").each(function () {
            var consent = this;
            function updateSignature() {
                $(consent).closest("form").find("[data-supervisor-signature]")
                    .val(consent.checked ? consent.dataset.signature : "");
            }
            $(consent).on("change", updateSignature);
            updateSignature();
        });

        var $themeToggle = $(".portal-theme-toggle");
        function setTheme(dark) {
            document.documentElement.dataset.portalTheme = dark ? "dark" : "light";
            $themeToggle.prop("hidden", false).attr({
                "aria-pressed": String(dark),
                title: dark ? "Turn off night mode" : "Turn on night mode"
            });
            $themeToggle.find(".fa").toggleClass("fa-sun-o", dark).toggleClass("fa-moon-o", !dark);
        }
        setTheme(document.documentElement.dataset.portalTheme === "dark");
        $themeToggle.on("click", function () {
            var dark = document.documentElement.dataset.portalTheme !== "dark";
            setTheme(dark);
            try {
                localStorage.setItem("acpPortalTheme", dark ? "dark" : "light");
            } catch (error) {
                console.warn("Unable to save night mode preference.", error);
            }
        });
        window.addEventListener("storage", function (event) {
            if (event.key === "acpPortalTheme" || event.key === null) {
                setTheme(event.newValue === "dark");
            }
        });

        var $sidebar = $("#sidebarMenu");
        var $row = $sidebar.closest(".row");
        var $toggle = $(".user-header-sidebar-toggle");
        var $backdrop = $(".portal-sidebar-backdrop");
        var mobile = window.matchMedia("(max-width: 767px)");
        var stateKey = "acpUserSidebarHidden";
        var desktopHidden = false;

        try {
            desktopHidden = localStorage.getItem(stateKey) === "1";
        } catch (error) {
            console.warn("Sidebar preferences are unavailable.", error);
        }

        function setHidden(hidden, restoreFocus) {
            $row.toggleClass("user-sidebar-hidden", hidden);
            $sidebar.prop("inert", mobile.matches && hidden);
            $("body").toggleClass("portal-sidebar-collapsed", !mobile.matches && hidden);
            var toggleLabel = mobile.matches
                ? (hidden ? "Open navigation" : "Close navigation")
                : (hidden ? "Expand navigation" : "Collapse navigation");
            $toggle.attr({
                "aria-expanded": String(!hidden),
                "aria-label": toggleLabel,
                title: toggleLabel
            });
            $backdrop.prop("hidden", !mobile.matches || hidden);
            $("body").toggleClass("portal-navigation-open", mobile.matches && !hidden);
            if (restoreFocus) {
                $toggle.trigger("focus");
            }
        }

        if ($sidebar.length) {
            $("body").addClass("portal-has-sidebar");
            $sidebar.siblings("main").attr({id: "portal-main", tabindex: "-1"});
            setHidden(mobile.matches || desktopHidden);

            $toggle.on("click", function () {
                var hidden = !$row.hasClass("user-sidebar-hidden");
                setHidden(hidden);
                if (mobile.matches && !hidden) {
                    $sidebar.find("a, button:not([hidden])").first().trigger("focus");
                } else if (!mobile.matches) {
                    desktopHidden = hidden;
                    try {
                        localStorage.setItem(stateKey, hidden ? "1" : "0");
                    } catch (error) {
                        console.warn("Unable to save sidebar preference.", error);
                    }
                }
            });
            $backdrop.on("click", function () { setHidden(true, true); });
            mobile.addEventListener("change", function () {
                setHidden(mobile.matches || desktopHidden);
            });
            $(document).on("keydown", function (event) {
                if (event.key === "Escape") {
                    if (mobile.matches && !$row.hasClass("user-sidebar-hidden")) {
                        setHidden(true, true);
                    }
                    $(".portal-dropdown[open]").each(function () {
                        $(this).removeAttr("open").children("summary").trigger("focus");
                    });
                }
                if (event.key === "Tab" && mobile.matches && !$row.hasClass("user-sidebar-hidden")) {
                    var $links = $sidebar.find("a, button:not([hidden])");
                    if (event.shiftKey && event.target === $links[0]) {
                        event.preventDefault();
                        $toggle.trigger("focus");
                    } else if (!event.shiftKey && event.target === $links[$links.length - 1]) {
                        event.preventDefault();
                        $toggle.trigger("focus");
                    } else if (event.target === $toggle[0]) {
                        event.preventDefault();
                        (event.shiftKey ? $links.last() : $links.first()).trigger("focus");
                    }
                }
            });
        } else {
            $toggle.remove();
        }

        $(".portal-dropdown").on("toggle", function () {
            if (this.open) {
                $(".portal-dropdown").not(this).removeAttr("open");
            }
        });
        $(document).on("click", function (event) {
            if (!$(event.target).closest(".portal-dropdown").length) {
                $(".portal-dropdown").removeAttr("open");
            }
        });

        var accordionSelector = ".portal-quick-accordion, .supervisor-accordion";
        var accordionStates = new WeakMap();
        var reducedMotion = window.matchMedia("(prefers-reduced-motion: reduce)");

        function resetAccordion(accordion) {
            var state = accordionStates.get(accordion);
            if (state && state.animation) {
                state.animation.cancel();
            }
            accordionStates.delete(accordion);
            accordion.open = false;
            accordion.style.overflow = "";
            accordion.classList.remove("portal-accordion-closing");
            var content = accordion.querySelector(".portal-quick-grid, .supervisor-accordion-content");
            if (content) {
                content.inert = false;
            }
        }

        $(accordionSelector).each(function () { resetAccordion(this); });
        window.addEventListener("pageshow", function () {
            $(accordionSelector).each(function () { resetAccordion(this); });
        });

        $(document).on("click", ".portal-quick-accordion > summary, .supervisor-accordion > summary", function (event) {
            var summary = this;
            var accordion = summary.parentElement;
            var content = accordion.querySelector(".portal-quick-grid, .supervisor-accordion-content");
            if (!content || typeof accordion.animate !== "function") {
                return;
            }

            event.preventDefault();
            var state = accordionStates.get(accordion) || {expanded: accordion.open, animation: null};
            var startHeight = accordion.getBoundingClientRect().height;
            state.expanded = !state.expanded;
            if (state.animation) {
                state.animation.cancel();
                state.animation = null;
            }
            accordionStates.set(accordion, state);
            content.inert = !state.expanded;
            accordion.classList.toggle("portal-accordion-closing", !state.expanded);

            if (reducedMotion.matches) {
                accordion.open = state.expanded;
                accordion.style.overflow = "";
                accordion.classList.remove("portal-accordion-closing");
                return;
            }

            if (state.expanded) {
                accordion.open = true;
            }
            accordion.style.overflow = "hidden";
            var style = window.getComputedStyle(accordion);
            var borders = parseFloat(style.borderTopWidth) + parseFloat(style.borderBottomWidth);
            var endHeight = state.expanded
                ? accordion.getBoundingClientRect().height
                : summary.getBoundingClientRect().height + borders;
            state.animation = accordion.animate(
                [{height: startHeight + "px"}, {height: endHeight + "px"}],
                {duration: 650, easing: "ease-in-out"}
            );
            state.animation.onfinish = function () {
                accordion.open = state.expanded;
                accordion.style.overflow = "";
                accordion.classList.remove("portal-accordion-closing");
                state.animation = null;
            };
        });

        var $convention = $(".portal-convention-form #convention_id");
        if ($convention.length) {
            $convention.select2({width: "100%"});
            $convention.on("change", function () {
                this.form.requestSubmit();
            });
        }
    });
})(jQuery);
