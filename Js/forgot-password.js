document.getElementById("forgotForm").addEventListener("submit", function(e) {
    e.preventDefault();
    const result = document.getElementById("result");
    result.style.color = "#374151";
    result.textContent = "Public password reset is not enabled. Contact your system administrator to verify your identity and restore access.";
});
