document.addEventListener('DOMContentLoaded', () => {
    const categoryFilter = document.getElementById('categoryFilter');
    const classTypeFilter = document.getElementById('classTypeFilter');
    const packageSections = document.querySelectorAll('.package-section');
    const noPackagesMessage = document.getElementById('noPackagesMessage');

    if (!categoryFilter || !classTypeFilter) return;

    function filterPackages() {
        const selectedCategory = categoryFilter.value.toLowerCase();
        const selectedClassType = classTypeFilter.value.toLowerCase();

        let totalVisiblePackages = 0;

        packageSections.forEach(section => {
            const sectionCategory = (section.dataset.sectionCategory || '').toLowerCase();
            const packageCards = section.querySelectorAll('.package-card');

            const categoryMatches =
                selectedCategory === 'all' ||
                selectedCategory === sectionCategory;

            let visibleCards = 0;

            packageCards.forEach(card => {
                const classType = (card.dataset.classType || '').toLowerCase();
                const classTypeMatches =
                    selectedClassType === 'all' ||
                    selectedClassType === classType;

                const isVisible = categoryMatches && classTypeMatches;
                card.hidden = !isVisible;
                card.style.display = isVisible ? '' : 'none';

                if (isVisible) {
                    visibleCards++;
                }
            });

            const isSectionVisible = visibleCards > 0;
            section.hidden = !isSectionVisible;
            section.style.display = isSectionVisible ? '' : 'none';

            totalVisiblePackages += visibleCards;
        });

        if (noPackagesMessage) {
            const isEmpty = totalVisiblePackages === 0;
            noPackagesMessage.hidden = !isEmpty;
            noPackagesMessage.style.display = isEmpty ? 'block' : 'none';
        }
    }

    categoryFilter.addEventListener('change', filterPackages);
    classTypeFilter.addEventListener('change', filterPackages);
});