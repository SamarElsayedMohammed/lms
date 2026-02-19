@extends('layouts.app')

@section('title')
    {{ __('Certificate Editor') }}
@endsection

@section('page-title')
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-3">
        <h1 class="mb-2 mb-md-0 flex-shrink-0">@yield('title'): <span class="d-block d-md-inline">{{ $certificate->name }}</span></h1>
        <div class="section-header-button w-100 w-md-auto" style="margin-left: auto;">
            <div class="d-flex flex-column flex-md-row gap-2 w-100 w-md-auto">
                <a href="{{ route('admin.certificates.index') }}" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> <span class="d-none d-sm-inline">{{ __('Back to Certificates') }}</span>
                    <span class="d-sm-none">{{ __('Back') }}</span>
                </a>
                <button type="button" class="btn btn-success" onclick="handleSaveDesign()">
                    <i class="fas fa-save"></i> <span class="d-none d-sm-inline">{{ __('Save Design') }}</span>
                    <span class="d-sm-none">{{ __('Save') }}</span>
                </button>
            </div>
        </div>
    </div>
@endsection

@section('main')
<div class="section">
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <div class="row">
                        <!-- Certificate Canvas -->
                        <div class="col-12 col-lg-8 mb-3 mb-lg-0">
                            <div class="certificate-editor-container" style="overflow-x: auto; -webkit-overflow-scrolling: touch;">
                                <div id="certificate-canvas" class="certificate-canvas" 
                                     style="position: relative; width: 100%;  min-height: 400px; height: 600px; margin: 0 auto; border: 2px dashed #ccc; background-image: url('{{ $certificate->background_image_url }}'); background-size: cover; background-position: center;">
                                    
                                    <!-- Draggable Title -->
                                    <div class="draggable-element" id="title-element" 
                                         style="position: absolute; top: 100px; left: 50%; transform: translateX(-50%); cursor: move; user-select: none; background: rgba(255,255,255,0.8); padding: 10px; border-radius: 5px; min-width: 200px; text-align: center;">
                                        <div class="element-content" contenteditable="true" style="font-size: 32px; font-weight: bold; color: #333;">
                                            {{ $certificate->title ?? 'Certificate of Completion' }}
                                        </div>
                                        <div class="element-controls" style="position: absolute; top: -25px; right: 0; display: none;">
                                            <button type="button" class="btn btn-sm btn-danger" onclick="removeElement('title-element')">
                                                <i class="fas fa-times"></i>
                                            </button>
                                        </div>
                                    </div>

                                    <!-- Draggable Subtitle -->
                                    <div class="draggable-element" id="subtitle-element" 
                                         style="position: absolute; top: 180px; left: 50%; transform: translateX(-50%); cursor: move; user-select: none; background: rgba(255,255,255,0.8); padding: 8px; border-radius: 5px; min-width: 300px; text-align: center;">
                                        <div class="element-content" contenteditable="true" style="font-size: 18px; color: #666;">
                                            {{ $certificate->subtitle ?? 'This is to certify that' }}
                                        </div>
                                        <div class="element-controls" style="position: absolute; top: -25px; right: 0; display: none;">
                                            <button type="button" class="btn btn-sm btn-danger" onclick="removeElement('subtitle-element')">
                                                <i class="fas fa-times"></i>
                                            </button>
                                        </div>
                                    </div>

                                    <!-- Draggable Student Name -->
                                    <div class="draggable-element" id="student-name-element" 
                                         style="position: absolute; top: 280px; left: 50%; transform: translateX(-50%); cursor: move; user-select: none; background: rgba(255,255,255,0.8); padding: 10px; border-radius: 5px; min-width: 200px; text-align: center;">
                                        <div class="element-content" contenteditable="true" style="font-size: 24px; font-weight: bold; color: #333;">
                                            [Student Name]
                                        </div>
                                        <div class="element-controls" style="position: absolute; top: -25px; right: 0; display: none;">
                                            <button type="button" class="btn btn-sm btn-danger" onclick="removeElement('student-name-element')">
                                                <i class="fas fa-times"></i>
                                            </button>
                                        </div>
                                    </div>

                                    <!-- Draggable Course Name -->
                                    <div class="draggable-element" id="course-name-element" 
                                         style="position: absolute; top: 350px; left: 50%; transform: translateX(-50%); cursor: move; user-select: none; background: rgba(255,255,255,0.8); padding: 8px; border-radius: 5px; min-width: 250px; text-align: center;">
                                        <div class="element-content" contenteditable="true" style="font-size: 20px; color: #333;">
                                            [Course Name]
                                        </div>
                                        <div class="element-controls" style="position: absolute; top: -25px; right: 0; display: none;">
                                            <button type="button" class="btn btn-sm btn-danger" onclick="removeElement('course-name-element')">
                                                <i class="fas fa-times"></i>
                                            </button>
                                        </div>
                                    </div>

                                    <!-- Draggable Date -->
                                    <div class="draggable-element" id="date-element" 
                                         style="position: absolute; top: 450px; left: 100px; cursor: move; user-select: none; background: rgba(255,255,255,0.8); padding: 8px; border-radius: 5px; min-width: 150px; text-align: center;">
                                        <div class="element-content" contenteditable="true" style="font-size: 16px; color: #666;">
                                            Date: [Completion Date]
                                        </div>
                                        <div class="element-controls" style="position: absolute; top: -25px; right: 0; display: none;">
                                            <button type="button" class="btn btn-sm btn-danger" onclick="removeElement('date-element')">
                                                <i class="fas fa-times"></i>
                                            </button>
                                        </div>
                                    </div>

                                    <!-- Draggable Signature -->
                                    <div class="draggable-element" id="signature-element" 
                                         style="position: absolute; top: 450px; right: 100px; cursor: move; user-select: none; background: rgba(255,255,255,0.8); padding: 8px; border-radius: 5px; min-width: 200px; text-align: center;">
                                        @if($certificate->signature_image)
                                        <img src="{{ $certificate->signature_image_url }}" alt="Signature" style="max-width: 150px; max-height: 80px;">
                                        @endif
                                        <div class="element-content" contenteditable="true" style="font-size: 14px; color: #666; margin-top: 5px;">
                                            {{ $certificate->signature_text ?? 'Director' }}
                                        </div>
                                        <div class="element-controls" style="position: absolute; top: -25px; right: 0; display: none;">
                                            <button type="button" class="btn btn-sm btn-danger" onclick="removeElement('signature-element')">
                                                <i class="fas fa-times"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Control Panel -->
                        <div class="col-12 col-lg-4">
                            <div class="control-panel">
                                <h5>{{ __('Add Elements') }}</h5>
                                <div class="btn-group-vertical w-100 mb-3">
                                    <button type="button" class="btn btn-outline-primary mb-2" onclick="addTextElement('text-element')">
                                        <i class="fas fa-font"></i> {{ __('Add Text') }}
                                    </button>
                                    <button type="button" class="btn btn-outline-primary mb-2" onclick="addImageElement()">
                                        <i class="fas fa-image"></i> {{ __('Add Image') }}
                                    </button>
                                    <button type="button" class="btn btn-outline-primary mb-2" onclick="addDateElement()">
                                        <i class="fas fa-calendar"></i> {{ __('Add Date') }}
                                    </button>
                                </div>

                                <h5>{{ __('Element Properties') }}</h5>
                                <div id="element-properties" class="element-properties" style="display: none;">
                                    <div class="form-group">
                                        <label>{{ __('Font Size') }}</label>
                                        <input type="range" id="font-size" class="form-control-range" min="12" max="48" value="16" onchange="updateElementProperty('fontSize', this.value)">
                                    </div>
                                    <div class="form-group">
                                        <label>{{ __('Font Color') }}</label>
                                        <input type="color" id="font-color" class="form-control" value="#333333" onchange="updateElementProperty('color', this.value)">
                                    </div>
                                    <div class="form-group">
                                        <label>{{ __('Background Color') }}</label>
                                        <input type="color" id="bg-color" class="form-control" value="#ffffff" onchange="updateElementProperty('backgroundColor', this.value)">
                                    </div>
                                    <div class="form-group">
                                        <label>{{ __('Opacity') }}</label>
                                        <input type="range" id="opacity" class="form-control-range" min="0" max="1" step="0.1" value="0.8" onchange="updateElementProperty('opacity', this.value)">
                                    </div>
                                </div>

                                <h5 class="mt-4">{{ __('Actions') }}</h5>
                                <div class="btn-group-vertical w-100">
                                    <button type="button" class="btn btn-warning mb-2" onclick="resetDesign()">
                                        <i class="fas fa-undo"></i> {{ __('Reset Design') }}
                                    </button>
                                    <button type="button" class="btn btn-info mb-2" onclick="previewCertificate()">
                                        <i class="fas fa-eye"></i> {{ __('Preview') }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('styles')
@endpush

@push('scripts')
<script>
let selectedElement = null;
let isDragging = false;
let dragOffset = { x: 0, y: 0 };

// Initialize drag and drop functionality
document.addEventListener('DOMContentLoaded', function() {
    initializeDragAndDrop();
    loadSavedDesign();
    
    // Allow text editing on contenteditable elements
    document.addEventListener('mousedown', function(e) {
        if (e.target.classList.contains('element-content') || e.target.closest('.element-content')) {
            // Stop event propagation to prevent drag from starting
            e.stopPropagation();
        }
    }, true);
    
    // Prevent drag when clicking directly on contenteditable
    document.addEventListener('click', function(e) {
        if (e.target.classList.contains('element-content') || e.target.closest('.element-content')) {
            // Focus the contenteditable element for editing
            const contentElement = e.target.classList.contains('element-content') 
                ? e.target 
                : e.target.closest('.element-content');
            if (contentElement) {
                contentElement.focus();
                // Place cursor at end of text
                const range = document.createRange();
                range.selectNodeContents(contentElement);
                range.collapse(false);
                const selection = window.getSelection();
                selection.removeAllRanges();
                selection.addRange(range);
            }
        }
    });
});

function initializeDragAndDrop() {
    const elements = document.querySelectorAll('.draggable-element');
    
    elements.forEach(element => {
        element.addEventListener('mousedown', startDrag);
        element.addEventListener('click', selectElement);
        element.addEventListener('mouseenter', showControls);
        element.addEventListener('mouseleave', hideControls);
    });
}

function loadSavedDesign() {
    @if($certificate->template_settings && is_array($certificate->template_settings))
        const savedDesign = @json($certificate->template_settings);
        console.log('Loading saved design:', savedDesign);
        
        if (savedDesign && savedDesign.elements) {
            const canvas = document.getElementById('certificate-canvas');
            const defaultElements = ['title-element', 'subtitle-element', 'student-name-element', 'course-name-element', 'date-element', 'signature-element'];
            
            // Clear all existing elements
            document.querySelectorAll('.draggable-element').forEach(element => {
                element.remove();
            });
            
            // Recreate all elements from saved design
            savedDesign.elements.forEach(savedElement => {
                let element;
                
                // Check if it's a default element
                if (defaultElements.includes(savedElement.id)) {
                    element = document.getElementById(savedElement.id);
                    if (!element) {
                        // Recreate default element if it doesn't exist
                        element = recreateDefaultElement(savedElement.id);
                    }
                } else {
                    // Create new element for non-default elements
                    element = createElementFromSaved(savedElement);
                }
                
                if (element) {
                    // Apply position
                    if (savedElement.isCentered && savedElement.leftStyle === '50%') {
                        element.style.left = '50%';
                        element.style.transform = savedElement.transform || 'translateX(-50%)';
                    } else if (savedElement.rightStyle && savedElement.rightStyle !== 'auto') {
                        element.style.right = savedElement.rightStyle;
                        element.style.left = 'auto';
                        element.style.transform = 'none';
                    } else {
                        element.style.left = savedElement.x + 'px';
                        element.style.top = savedElement.y + 'px';
                        element.style.transform = savedElement.transform || 'none';
                    }
                    element.style.top = savedElement.y + 'px';
                    
                    // Apply content based on element type
                    const contentElement = element.querySelector('.element-content');
                    let imgElement = element.querySelector('img');
                    
                    // Handle signature element with image
                    if (savedElement.id === 'signature-element' && savedElement.hasImage && savedElement.imageSrc) {
                        if (!imgElement) {
                            // Create img element if it doesn't exist
                            imgElement = document.createElement('img');
                            imgElement.src = savedElement.imageSrc;
                            imgElement.alt = 'Signature';
                            imgElement.style.cssText = 'max-width: 150px; max-height: 80px; display: block; margin: 0 auto;';
                            element.insertBefore(imgElement, contentElement);
                        } else {
                            imgElement.src = savedElement.imageSrc;
                        }
                    }
                    
                    if (contentElement && savedElement.content) {
                        // Text-based elements
                        contentElement.innerHTML = savedElement.content;
                    } else if (imgElement && savedElement.content && !contentElement) {
                        // Image-only elements
                        imgElement.src = savedElement.content;
                    }
                    
                    // Apply styles
                    if (savedElement.styles) {
                        if (contentElement) {
                            // Apply text styles to content element
                            if (savedElement.styles.fontSize) {
                                contentElement.style.fontSize = savedElement.styles.fontSize;
                            }
                            if (savedElement.styles.color) {
                                contentElement.style.color = savedElement.styles.color;
                            }
                            if (savedElement.styles.fontWeight) {
                                contentElement.style.fontWeight = savedElement.styles.fontWeight;
                            }
                            if (savedElement.styles.fontStyle) {
                                contentElement.style.fontStyle = savedElement.styles.fontStyle;
                            }
                            if (savedElement.styles.textAlign) {
                                contentElement.style.textAlign = savedElement.styles.textAlign;
                            }
                        }
                        
                        // Apply common styles to element
                        if (savedElement.styles.backgroundColor) {
                            element.style.backgroundColor = savedElement.styles.backgroundColor;
                        }
                        if (savedElement.styles.opacity) {
                            element.style.opacity = savedElement.styles.opacity;
                        }
                    }
                }
            });
            
            // Re-initialize drag and drop for all elements
            initializeDragAndDrop();
        }
    @endif
}

function recreateDefaultElement(elementId) {
    const canvas = document.getElementById('certificate-canvas');
    let element;
    
    switch(elementId) {
        case 'title-element':
            element = createTitleElement();
            break;
        case 'subtitle-element':
            element = createSubtitleElement();
            break;
        case 'student-name-element':
            element = createStudentNameElement();
            break;
        case 'course-name-element':
            element = createCourseNameElement();
            break;
        case 'date-element':
            element = createDateElement();
            break;
        case 'signature-element':
            element = createSignatureElement();
            break;
    }
    
    if (element) {
        canvas.appendChild(element);
    }
    
    return element;
}

function createElementFromSaved(savedElement) {
    const canvas = document.getElementById('certificate-canvas');
    const element = document.createElement('div');
    element.className = 'draggable-element';
    element.id = savedElement.id;
    element.style.cssText = 'position: absolute; cursor: move; user-select: none; background: rgba(255,255,255,0.8); padding: 8px; border-radius: 5px; min-width: 150px; text-align: center;';
    
    if (savedElement.type === 'image') {
        element.innerHTML = `
            <img src="${savedElement.content}" alt="Image" style="max-width: 150px; max-height: 100px;">
            <div class="element-controls" style="position: absolute; top: -25px; right: 0; display: none;">
                <button type="button" class="btn btn-sm btn-danger" onclick="removeElement('${savedElement.id}')">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        `;
    } else {
        element.innerHTML = `
            <div class="element-content" contenteditable="true" style="font-size: 16px; color: #333;">${savedElement.content || 'New Text'}</div>
            <div class="element-controls" style="position: absolute; top: -25px; right: 0; display: none;">
                <button type="button" class="btn btn-sm btn-danger" onclick="removeElement('${savedElement.id}')">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        `;
    }
    
    canvas.appendChild(element);
    return element;
}

function createTitleElement() {
    const element = document.createElement('div');
    element.className = 'draggable-element';
    element.id = 'title-element';
    element.style.cssText = 'position: absolute; top: 100px; left: 50%; transform: translateX(-50%); cursor: move; user-select: none; background: rgba(255,255,255,0.8); padding: 10px; border-radius: 5px; min-width: 200px; text-align: center;';
    element.innerHTML = `
        <div class="element-content" contenteditable="true" style="font-size: 32px; font-weight: bold; color: #333;">{{ $certificate->title ?? 'Certificate of Completion' }}</div>
        <div class="element-controls" style="position: absolute; top: -25px; right: 0; display: none;">
            <button type="button" class="btn btn-sm btn-danger" onclick="removeElement('title-element')">
                <i class="fas fa-times"></i>
            </button>
        </div>
    `;
    return element;
}

function createSubtitleElement() {
    const element = document.createElement('div');
    element.className = 'draggable-element';
    element.id = 'subtitle-element';
    element.style.cssText = 'position: absolute; top: 180px; left: 50%; transform: translateX(-50%); cursor: move; user-select: none; background: rgba(255,255,255,0.8); padding: 8px; border-radius: 5px; min-width: 300px; text-align: center;';
    element.innerHTML = `
        <div class="element-content" contenteditable="true" style="font-size: 18px; color: #666;">{{ $certificate->subtitle ?? 'This is to certify that' }}</div>
        <div class="element-controls" style="position: absolute; top: -25px; right: 0; display: none;">
            <button type="button" class="btn btn-sm btn-danger" onclick="removeElement('subtitle-element')">
                <i class="fas fa-times"></i>
            </button>
        </div>
    `;
    return element;
}

function createStudentNameElement() {
    const element = document.createElement('div');
    element.className = 'draggable-element';
    element.id = 'student-name-element';
    element.style.cssText = 'position: absolute; top: 280px; left: 50%; transform: translateX(-50%); cursor: move; user-select: none; background: rgba(255,255,255,0.8); padding: 10px; border-radius: 5px; min-width: 200px; text-align: center;';
    element.innerHTML = `
        <div class="element-content" contenteditable="true" style="font-size: 24px; font-weight: bold; color: #333;">[Student Name]</div>
        <div class="element-controls" style="position: absolute; top: -25px; right: 0; display: none;">
            <button type="button" class="btn btn-sm btn-danger" onclick="removeElement('student-name-element')">
                <i class="fas fa-times"></i>
            </button>
        </div>
    `;
    return element;
}

function createCourseNameElement() {
    const element = document.createElement('div');
    element.className = 'draggable-element';
    element.id = 'course-name-element';
    element.style.cssText = 'position: absolute; top: 350px; left: 50%; transform: translateX(-50%); cursor: move; user-select: none; background: rgba(255,255,255,0.8); padding: 8px; border-radius: 5px; min-width: 250px; text-align: center;';
    element.innerHTML = `
        <div class="element-content" contenteditable="true" style="font-size: 20px; color: #333;">[Course Name]</div>
        <div class="element-controls" style="position: absolute; top: -25px; right: 0; display: none;">
            <button type="button" class="btn btn-sm btn-danger" onclick="removeElement('course-name-element')">
                <i class="fas fa-times"></i>
            </button>
        </div>
    `;
    return element;
}

function createDateElement() {
    const element = document.createElement('div');
    element.className = 'draggable-element';
    element.id = 'date-element';
    element.style.cssText = 'position: absolute; top: 450px; left: 100px; cursor: move; user-select: none; background: rgba(255,255,255,0.8); padding: 8px; border-radius: 5px; min-width: 150px; text-align: center;';
    element.innerHTML = `
        <div class="element-content" contenteditable="true" style="font-size: 16px; color: #666;">Date: [Completion Date]</div>
        <div class="element-controls" style="position: absolute; top: -25px; right: 0; display: none;">
            <button type="button" class="btn btn-sm btn-danger" onclick="removeElement('date-element')">
                <i class="fas fa-times"></i>
            </button>
        </div>
    `;
    return element;
}

function createSignatureElement() {
    const element = document.createElement('div');
    element.className = 'draggable-element';
    element.id = 'signature-element';
    element.style.cssText = 'position: absolute; top: 450px; right: 100px; cursor: move; user-select: none; background: rgba(255,255,255,0.8); padding: 8px; border-radius: 5px; min-width: 200px; text-align: center;';
    element.innerHTML = `
        @if($certificate->signature_image)
        <img src="{{ $certificate->signature_image_url }}" alt="Signature" style="max-width: 150px; max-height: 80px;">
        @endif
        <div class="element-content" contenteditable="true" style="font-size: 14px; color: #666; margin-top: 5px;">{{ $certificate->signature_text ?? 'Director' }}</div>
        <div class="element-controls" style="position: absolute; top: -25px; right: 0; display: none;">
            <button type="button" class="btn btn-sm btn-danger" onclick="removeElement('signature-element')">
                <i class="fas fa-times"></i>
            </button>
        </div>
    `;
    return element;
}

function startDrag(e) {
    // Don't start drag if clicking on controls or contenteditable area
    if (e.target.classList.contains('element-controls') || 
        e.target.closest('.element-controls') ||
        e.target.classList.contains('element-content') ||
        e.target.closest('.element-content')) {
        return;
    }
    
    selectedElement = e.currentTarget;
    isDragging = true;
    
    const rect = selectedElement.getBoundingClientRect();
    const canvasRect = document.getElementById('certificate-canvas').getBoundingClientRect();
    
    dragOffset.x = e.clientX - rect.left;
    dragOffset.y = e.clientY - rect.top;
    
    selectedElement.style.zIndex = '1000';
    selectedElement.style.cursor = 'grabbing';
    
    document.addEventListener('mousemove', drag);
    document.addEventListener('mouseup', stopDrag);
    
    e.preventDefault();
}

function drag(e) {
    if (!isDragging || !selectedElement) return;
    
    const canvas = document.getElementById('certificate-canvas');
    const canvasRect = canvas.getBoundingClientRect();
    
    let newX = e.clientX - canvasRect.left - dragOffset.x;
    let newY = e.clientY - canvasRect.top - dragOffset.y;
    
    // Keep element within canvas bounds
    newX = Math.max(0, Math.min(newX, canvas.offsetWidth - selectedElement.offsetWidth));
    newY = Math.max(0, Math.min(newY, canvas.offsetHeight - selectedElement.offsetHeight));
    
    selectedElement.style.left = newX + 'px';
    selectedElement.style.top = newY + 'px';
    selectedElement.style.transform = 'none';
}

function stopDrag() {
    if (selectedElement) {
        selectedElement.style.cursor = 'move';
        selectedElement.style.zIndex = '1';
    }
    
    isDragging = false;
    selectedElement = null;
    
    document.removeEventListener('mousemove', drag);
    document.removeEventListener('mouseup', stopDrag);
}

function selectElement(e) {
    // Don't select if clicking on controls or contenteditable area (let user edit text)
    if (e.target.classList.contains('element-controls') || 
        e.target.closest('.element-controls') ||
        e.target.classList.contains('element-content') ||
        e.target.closest('.element-content')) {
        // If clicking on contenteditable, still select the element but don't prevent editing
        if (e.target.classList.contains('element-content') || e.target.closest('.element-content')) {
            // Remove previous selection
            document.querySelectorAll('.draggable-element').forEach(el => {
                el.classList.remove('selected');
            });
            
            // Select current element
            selectedElement = e.currentTarget;
            selectedElement.classList.add('selected');
            
            // Show properties panel
            showElementProperties(selectedElement);
        }
        return;
    }
    
    // Remove previous selection
    document.querySelectorAll('.draggable-element').forEach(el => {
        el.classList.remove('selected');
    });
    
    // Select current element
    selectedElement = e.currentTarget;
    selectedElement.classList.add('selected');
    
    // Show properties panel
    showElementProperties(selectedElement);
}

function showControls(e) {
    const controls = e.currentTarget.querySelector('.element-controls');
    if (controls) {
        controls.style.display = 'block';
    }
}

function hideControls(e) {
    const controls = e.currentTarget.querySelector('.element-controls');
    if (controls) {
        controls.style.display = 'none';
    }
}

function showElementProperties(element) {
    const propertiesPanel = document.getElementById('element-properties');
    propertiesPanel.style.display = 'block';
    
    // Update property controls with current element values
    const computedStyle = window.getComputedStyle(element.querySelector('.element-content'));
    
    document.getElementById('font-size').value = parseInt(computedStyle.fontSize) || 16;
    document.getElementById('font-color').value = rgbToHex(computedStyle.color) || '#333333';
    document.getElementById('bg-color').value = rgbToHex(computedStyle.backgroundColor) || '#ffffff';
    document.getElementById('opacity').value = computedStyle.opacity || '0.8';
}

function updateElementProperty(property, value) {
    if (!selectedElement) return;
    
    const content = selectedElement.querySelector('.element-content');
    
    switch(property) {
        case 'fontSize':
            content.style.fontSize = value + 'px';
            break;
        case 'color':
            content.style.color = value;
            break;
        case 'backgroundColor':
            selectedElement.style.backgroundColor = value;
            break;
        case 'opacity':
            selectedElement.style.opacity = value;
            break;
    }
}

function addTextElement(type) {
    const canvas = document.getElementById('certificate-canvas');
    const elementId = 'text-element-' + Date.now();
    
    const newElement = document.createElement('div');
    newElement.className = 'draggable-element';
    newElement.id = elementId;
    newElement.style.cssText = 'position: absolute; top: 200px; left: 200px; cursor: move; user-select: none; background: rgba(255,255,255,0.8); padding: 8px; border-radius: 5px; min-width: 150px; text-align: center;';
    
    newElement.innerHTML = `
        <div class="element-content" contenteditable="true" style="font-size: 16px; color: #333;">New Text</div>
        <div class="element-controls" style="position: absolute; top: -25px; right: 0; display: none;">
            <button type="button" class="btn btn-sm btn-danger" onclick="removeElement('${elementId}')">
                <i class="fas fa-times"></i>
            </button>
        </div>
    `;
    
    canvas.appendChild(newElement);
    
    // Add event listeners
    newElement.addEventListener('mousedown', startDrag);
    newElement.addEventListener('click', selectElement);
    newElement.addEventListener('mouseenter', showControls);
    newElement.addEventListener('mouseleave', hideControls);
    
    // Select the new element
    selectElement({ currentTarget: newElement });
}

function addImageElement() {
    const input = document.createElement('input');
    input.type = 'file';
    input.accept = 'image/*';
    input.onchange = function(e) {
        const file = e.target.files[0];
        if (file) {
            const reader = new FileReader();
            reader.onload = function(e) {
                addImageToCanvas(e.target.result);
            };
            reader.readAsDataURL(file);
        }
    };
    input.click();
}

function addImageToCanvas(imageSrc) {
    const canvas = document.getElementById('certificate-canvas');
    const elementId = 'image-element-' + Date.now();
    
    const newElement = document.createElement('div');
    newElement.className = 'draggable-element';
    newElement.id = elementId;
    newElement.style.cssText = 'position: absolute; top: 200px; left: 200px; cursor: move; user-select: none; background: rgba(255,255,255,0.8); padding: 8px; border-radius: 5px; min-width: 100px; text-align: center;';
    
    newElement.innerHTML = `
        <img src="${imageSrc}" alt="Image" style="max-width: 150px; max-height: 100px;">
        <div class="element-controls" style="position: absolute; top: -25px; right: 0; display: none;">
            <button type="button" class="btn btn-sm btn-danger" onclick="removeElement('${elementId}')">
                <i class="fas fa-times"></i>
            </button>
        </div>
    `;
    
    canvas.appendChild(newElement);
    
    // Add event listeners
    newElement.addEventListener('mousedown', startDrag);
    newElement.addEventListener('click', selectElement);
    newElement.addEventListener('mouseenter', showControls);
    newElement.addEventListener('mouseleave', hideControls);
    
    // Select the new element
    selectElement({ currentTarget: newElement });
}

function addDateElement() {
    const canvas = document.getElementById('certificate-canvas');
    const elementId = 'date-element-' + Date.now();
    
    const newElement = document.createElement('div');
    newElement.className = 'draggable-element';
    newElement.id = elementId;
    newElement.style.cssText = 'position: absolute; top: 200px; left: 200px; cursor: move; user-select: none; background: rgba(255,255,255,0.8); padding: 8px; border-radius: 5px; min-width: 150px; text-align: center;';
    
    newElement.innerHTML = `
        <div class="element-content" contenteditable="true" style="font-size: 16px; color: #666;">Date: [Completion Date]</div>
        <div class="element-controls" style="position: absolute; top: -25px; right: 0; display: none;">
            <button type="button" class="btn btn-sm btn-danger" onclick="removeElement('${elementId}')">
                <i class="fas fa-times"></i>
            </button>
        </div>
    `;
    
    canvas.appendChild(newElement);
    
    // Add event listeners
    newElement.addEventListener('mousedown', startDrag);
    newElement.addEventListener('click', selectElement);
    newElement.addEventListener('mouseenter', showControls);
    newElement.addEventListener('mouseleave', hideControls);
    
    // Select the new element
    selectElement({ currentTarget: newElement });
}

function removeElement(elementId) {
    const element = document.getElementById(elementId);
    if (element) {
        element.remove();
    }
}

async function resetDesign() {
    if (!confirm('Are you sure you want to reset the design? This will restore the original layout and remove all custom elements.')) {
        return;
    }
    
    try {
        const canvas = document.getElementById('certificate-canvas');
        const defaultElementIds = ['title-element', 'subtitle-element', 'student-name-element', 'course-name-element', 'date-element', 'signature-element'];
        
        // Remove all non-default elements
        const allElements = canvas.querySelectorAll('.draggable-element');
        allElements.forEach(element => {
            if (!defaultElementIds.includes(element.id)) {
                element.remove();
            }
        });
        
        // Reset default elements to original positions and styles
        const defaultPositions = {
            'title-element': {
                top: '100px',
                left: '50%',
                transform: 'translateX(-50%)',
                fontSize: '32px',
                fontWeight: 'bold',
                color: '#333',
                backgroundColor: 'rgba(255,255,255,0.8)'
            },
            'subtitle-element': {
                top: '180px',
                left: '50%',
                transform: 'translateX(-50%)',
                fontSize: '18px',
                color: '#666',
                backgroundColor: 'rgba(255,255,255,0.8)'
            },
            'student-name-element': {
                top: '280px',
                left: '50%',
                transform: 'translateX(-50%)',
                fontSize: '24px',
                fontWeight: 'bold',
                color: '#333',
                backgroundColor: 'rgba(255,255,255,0.8)'
            },
            'course-name-element': {
                top: '350px',
                left: '50%',
                transform: 'translateX(-50%)',
                fontSize: '20px',
                color: '#333',
                backgroundColor: 'rgba(255,255,255,0.8)'
            },
            'date-element': {
                top: '450px',
                left: '100px',
                transform: 'none',
                fontSize: '16px',
                color: '#666',
                backgroundColor: 'rgba(255,255,255,0.8)'
            },
            'signature-element': {
                top: '450px',
                right: '100px',
                left: 'auto',
                transform: 'none',
                fontSize: '14px',
                color: '#666',
                backgroundColor: 'rgba(255,255,255,0.8)'
            }
        };
        
        const defaultContent = {
            'title-element': '{{ $certificate->title ?? "Certificate of Completion" }}',
            'subtitle-element': '{{ $certificate->subtitle ?? "This is to certify that" }}',
            'student-name-element': '[Student Name]',
            'course-name-element': '[Course Name]',
            'date-element': 'Date: [Completion Date]',
            'signature-element': '{{ $certificate->signature_text ?? "Director" }}'
        };
        
        // Reset each default element
        defaultElementIds.forEach(elementId => {
            const element = document.getElementById(elementId);
            if (element) {
                const config = defaultPositions[elementId];
                
                // Reset position
                element.style.top = config.top;
                element.style.left = config.left || 'auto';
                element.style.right = config.right || 'auto';
                element.style.transform = config.transform || 'none';
                element.style.backgroundColor = config.backgroundColor;
                
                // Reset content
                const contentElement = element.querySelector('.element-content');
                if (contentElement) {
                    // For signature element, preserve image if it exists
                    if (elementId === 'signature-element') {
                        const existingImg = element.querySelector('img');
                        if (existingImg) {
                            // Keep the image, just update text
                            contentElement.innerHTML = defaultContent[elementId];
                        } else {
                            // No image, just set text
                            contentElement.innerHTML = defaultContent[elementId];
                        }
                    } else {
                        contentElement.innerHTML = defaultContent[elementId];
                    }
                    contentElement.style.fontSize = config.fontSize;
                    contentElement.style.fontWeight = config.fontWeight || 'normal';
                    contentElement.style.color = config.color;
                    contentElement.style.fontStyle = 'normal';
                    contentElement.style.textAlign = 'center';
                }
            } else {
                // Recreate element if it doesn't exist
                const recreatedElement = recreateDefaultElement(elementId);
                if (recreatedElement) {
                    // Re-initialize drag for recreated element
                    recreatedElement.addEventListener('mousedown', startDrag);
                    recreatedElement.addEventListener('click', selectElement);
                    recreatedElement.addEventListener('mouseenter', showControls);
                    recreatedElement.addEventListener('mouseleave', hideControls);
                }
            }
        });
        
        // Clear template_settings from server
        const response = await fetch(`{{ route('admin.certificates.update-design', $certificate) }}`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
            },
            body: JSON.stringify({
                template_settings: null
            })
        });
        
        if (response.ok) {
            const data = await response.json();
            if (data.success) {
                // Re-initialize drag and drop
                initializeDragAndDrop();
                alert('Design reset successfully!');
            } else {
                throw new Error(data.message || 'Failed to reset design');
            }
        } else {
            throw new Error(`HTTP ${response.status}: ${response.statusText}`);
        }
    } catch (error) {
        console.error('Error resetting design:', error);
        alert('An error occurred while resetting the design: ' + error.message);
    }
}

async function previewCertificate() {
    // First save the current design before previewing
    try {
        await saveCertificateDesign();
    } catch (error) {
        console.error('Error saving design:', error);
        // Still open preview even if save fails
    }
    
    // Open preview in a new window/tab
    const previewUrl = '{{ route("admin.certificates.preview", $certificate) }}';
    window.open(previewUrl, '_blank');
}

async function saveCertificateDesign() {
    const canvas = document.getElementById('certificate-canvas');
    const elements = canvas.querySelectorAll('.draggable-element');
    
    const design = {
        width: canvas.offsetWidth,
        height: canvas.offsetHeight,
        elements: []
    };
    
    elements.forEach(element => {
        const rect = element.getBoundingClientRect();
        const canvasRect = canvas.getBoundingClientRect();
        
        // Check if element is centered
        const elementComputedStyle = window.getComputedStyle(element);
        const transform = element.style.transform || elementComputedStyle.transform || 'none';
        const leftStyle = element.style.left || elementComputedStyle.left;
        const rightStyle = element.style.right || elementComputedStyle.right;
        
        // Check if element has explicit centering styles
        let isCentered = leftStyle === '50%' || (transform !== 'none' && transform.includes('translateX(-50%)'));
        
        // If not explicitly centered, check if position is near center (for dragged elements)
        if (!isCentered && leftStyle && leftStyle !== 'auto' && !leftStyle.includes('%')) {
            const elementX = rect.left - canvasRect.left;
            const canvasWidth = canvas.offsetWidth;
            const elementWidth = element.offsetWidth;
            const centerX = (canvasWidth / 2) - (elementWidth / 2);
            // If element is within 30px of center, consider it centered
            if (Math.abs(elementX - centerX) < 30) {
                isCentered = true;
            }
        }
        
        // Get content based on element type
        let content = '';
        let styles = {};
        let hasImage = false;
        let imageSrc = '';
        
        // Check for signature element with image
        if (element.id === 'signature-element') {
            const imgElement = element.querySelector('img');
            if (imgElement) {
                hasImage = true;
                imageSrc = imgElement.src;
            }
        }
        
        const contentElement = element.querySelector('.element-content');
        if (contentElement) {
            // Text-based elements - save all text styles
            content = contentElement.innerHTML;
            const computedStyle = window.getComputedStyle(contentElement);
            styles = {
                fontSize: contentElement.style.fontSize || computedStyle.fontSize,
                color: contentElement.style.color || computedStyle.color,
                fontWeight: contentElement.style.fontWeight || computedStyle.fontWeight,
                fontStyle: contentElement.style.fontStyle || computedStyle.fontStyle,
                fontFamily: contentElement.style.fontFamily || computedStyle.fontFamily,
                textAlign: contentElement.style.textAlign || computedStyle.textAlign,
                lineHeight: contentElement.style.lineHeight || computedStyle.lineHeight,
                letterSpacing: contentElement.style.letterSpacing || computedStyle.letterSpacing,
                textDecoration: contentElement.style.textDecoration || computedStyle.textDecoration,
                backgroundColor: element.style.backgroundColor || elementComputedStyle.backgroundColor,
                opacity: element.style.opacity !== '' ? element.style.opacity : (elementComputedStyle.opacity || '1')
            };
        } else {
            // Image elements
            const imgElement = element.querySelector('img');
            if (imgElement) {
                content = imgElement.src;
                hasImage = true;
                imageSrc = imgElement.src;
            }
            styles = {
                backgroundColor: element.style.backgroundColor || elementComputedStyle.backgroundColor,
                opacity: element.style.opacity !== '' ? element.style.opacity : (elementComputedStyle.opacity || '1')
            };
        }
        
        design.elements.push({
            id: element.id,
            type: element.id.split('-')[0],
            x: rect.left - canvasRect.left,
            y: rect.top - canvasRect.top,
            width: element.offsetWidth,
            height: element.offsetHeight,
            content: content,
            styles: styles,
            isCentered: isCentered,
            leftStyle: leftStyle,
            rightStyle: rightStyle,
            transform: transform,
            hasImage: hasImage,
            imageSrc: imageSrc
        });
    });
    
    // Send design to server
    console.log('Saving design:', design);
    
    // Use the new updateDesign endpoint
    const response = await fetch(`{{ route('admin.certificates.update-design', $certificate) }}`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
        },
        body: JSON.stringify({
            template_settings: design
        })
    });
    
    console.log('Response status:', response.status);
    console.log('Response headers:', response.headers);
    
    if (!response.ok) {
        throw new Error(`HTTP ${response.status}: ${response.statusText}`);
    }
    
    const data = await response.json();
    console.log('Response data:', data);
    
    if (data.success) {
        // Don't show alert or reload when called from preview
        // Just return success
        return true;
    } else {
        throw new Error(data.message || 'Unknown error');
    }
}

async function handleSaveDesign() {
    try {
        await saveCertificateDesign();
        alert('Certificate design saved successfully!');
        // Reload the page to show the saved changes
        location.reload();
    } catch (error) {
        console.error('Error:', error);
        alert('An error occurred while saving the design: ' + error.message);
    }
}

function rgbToHex(rgb) {
    if (!rgb) return '#000000';
    const result = rgb.match(/\d+/g);
    if (!result) return '#000000';
    return "#" + ((1 << 24) + (parseInt(result[0]) << 16) + (parseInt(result[1]) << 8) + parseInt(result[2])).toString(16).slice(1);
}

// Add CSS for selected element
const style = document.createElement('style');
style.textContent = `
    .draggable-element.selected {
        border: 2px solid #007bff !important;
        box-shadow: 0 0 10px rgba(0, 123, 255, 0.5);
    }
    .draggable-element:hover {
        border: 1px solid #007bff;
    }
`;
document.head.appendChild(style);
</script>
@endpush
