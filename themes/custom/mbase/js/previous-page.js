// Where are we?
var currentLoc = window.location.href;
// Where were we?
var previousLoc = document.referrer;
// Are we in the right section?
var limitExec = currentLoc.includes("news-and-resources");

if (limitExec === true) {
    // Assign the URL
    document.getElementById("previous-page").setAttribute("href", previousLoc);
}