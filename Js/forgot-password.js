document.getElementById("forgotForm").addEventListener("submit", function(e){

    e.preventDefault();

    const email = document.getElementById("forgotEmail").value;

    document.getElementById("result").style.color = "green";

    document.getElementById("result").innerHTML =
    "If an account with <b>" +
    email +
    "</b> exists, a password reset link has been sent.";

});