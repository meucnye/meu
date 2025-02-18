function sendCommand(action) {
    fetch(`http://172.20.10.3/phaman/control.php?action=${action}`)
        .then(response => response.text())
        .then(data => console.log(data))
        .catch(error => console.error('Error:', error));
}

document.getElementById("openButton").addEventListener("click", function() {
    sendCommand("open");
});

document.getElementById("closeButton").addEventListener("click", function() {
    sendCommand("close");
});

document.getElementById("autoModeToggle").addEventListener("change", function() {
    sendCommand(this.checked ? "auto_on" : "auto_off");
});
