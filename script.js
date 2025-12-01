//
// This JavaScript file provides basic dynamic functionality.
// Currently, it handles the menu toggle for a responsive layout.
//
document.addEventListener('DOMContentLoaded', () => {
    const menuToggle = document.querySelector('.menu-toggle');
    const sidebar = document.querySelector('.sidebar');
    const container = document.querySelector('.container');

    // Toggles the sidebar on and off
    menuToggle.addEventListener('click', () => {
        container.classList.toggle('sidebar-closed');
    });

    // Simple functionality to show different tabs, if you add them later
    const tabButtons = document.querySelectorAll('.tab-button');
    const tabContents = document.querySelectorAll('.tab-content');

    tabButtons.forEach(button => {
        button.addEventListener('click', () => {
            const tabId = button.dataset.tab;

            // Remove active class from all buttons and contents
            tabButtons.forEach(btn => btn.classList.remove('active'));
            tabContents.forEach(content => content.style.display = 'none');

            // Add active class to the clicked button and show the corresponding content
            button.classList.add('active');
            document.getElementById(tabId).style.display = 'block';
        });
    });
});
