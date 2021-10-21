window.addEventListener('load', function () {
    const bnfDisplay = new rrdiagram.bnfdisplay.BNFDisplay();
    bnfDisplay.replaceBNF('railroad-bnf', 'railroad-bnf-svg');
});

