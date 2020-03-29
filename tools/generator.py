file = open("HTMLFunctions.txt","w+")
read = open("a.txt",'r')

functions = []
i = 0
while i != -1:
    line = read.readline()
    line,sep,tail = (line.partition('>'))
    if (line == ''):
        break
    functions.append(0)
    functions[i] = (line.replace('<', '')).replace(' ','')
    i += 1

i = 0
while i < len(functions):
    if (i != 0):
        file.write('\n')
    else:
        file.write('const HTML_ELEMENTS = [')
        j = 0
        while j < len(functions):
            file.write(':'+functions[j])
            if (j != len(functions)-1):
                file.write(', ')
            j += 1
        file.write('];\n\n')
    file.write('$'+functions[i].replace('/', '').replace('!', '')+' = function(...$elements) {\n  return new \Tina4\HTMLElement(":' + functions[i].replace('/', '').replace('!', '') + '", $elements);\n};')
    i += 1

