/**
 * Course Details API Example
 * This file demonstrates how to call the enhanced course details API
 * and display comprehensive course information including:
 * - Course description
 * - What you'll learn
 * - Requirements
 * - Course content with chapters, lectures, assignments, and quizzes
 */

class CourseDetailsManager {
    constructor() {
        this.apiBaseUrl = '/api';
        this.courseId = null;
        this.courseSlug = null;
    }

    /**
     * Initialize course details by ID or slug
     * @param {Object} params - Parameters object
     * @param {number} [params.id] - Course ID
     * @param {string} [params.slug] - Course slug
     */
    async initCourseDetails(params = {}) {
        try {
            if (params.id) {
                this.courseId = params.id;
                await this.loadCourseById(params.id);
            } else if (params.slug) {
                this.courseSlug = params.slug;
                await this.loadCourseBySlug(params.slug);
            } else {
                throw new Error('Course ID or slug is required');
            }
        } catch (error) {
            console.error('Error initializing course details:', error);
            this.showError('Failed to load course details');
        }
    }

    /**
     * Load course details by ID
     * @param {number} courseId 
     */
    async loadCourseById(courseId) {
        const response = await fetch(`${this.apiBaseUrl}/get-course-details?id=${courseId}`);
        const data = await response.json();
        
        if (data.success) {
            this.displayCourseDetails(data.data);
        } else {
            throw new Error(data.message || 'Failed to load course');
        }
    }

    /**
     * Load course details by slug
     * @param {string} courseSlug 
     */
    async loadCourseBySlug(courseSlug) {
        const response = await fetch(`${this.apiBaseUrl}/get-course-details?slug=${courseSlug}`);
        const data = await response.json();
        
        if (data.success) {
            this.displayCourseDetails(data.data);
        } else {
            throw new Error(data.message || 'Failed to load course');
        }
    }

    /**
     * Display comprehensive course details
     * @param {Object} courseData 
     */
    displayCourseDetails(courseData) {
        // Display basic course information
        this.displayBasicInfo(courseData);
        
        // Display course description
        this.displayDescription(courseData.description);
        
        // Display what you'll learn
        this.displayLearnings(courseData.learnings);
        
        // Display requirements
        this.displayRequirements(courseData.requirements);
        
        // Display course content
        this.displayCourseContent(courseData.course_content);
        
        // Display course statistics
        this.displayCourseStatistics(courseData.course_statistics);
    }

    /**
     * Display basic course information
     * @param {Object} courseData 
     */
    displayBasicInfo(courseData) {
        const basicInfoHtml = `
            <div class="course-header">
                <h1 class="course-title">${courseData.title}</h1>
                <div class="course-meta">
                    <span class="course-category">${courseData.category?.name || 'Uncategorized'}</span>
                    <span class="course-level">${courseData.level || 'Beginner'}</span>
                    <span class="course-type">${courseData.course_type || 'Paid'}</span>
                </div>
                <div class="course-rating">
                    <span class="rating-stars">${this.generateStars(courseData.ratings.average)}</span>
                    <span class="rating-count">(${courseData.ratings.count} ratings)</span>
                </div>
                <div class="course-price">
                    ${courseData.discounted_price ? 
                        `<span class="original-price">$${courseData.price}</span>
                         <span class="discounted-price">$${courseData.discounted_price}</span>
                         <span class="discount-badge">${courseData.discount_percentage}% OFF</span>` :
                        `<span class="price">$${courseData.price}</span>`
                    }
                </div>
            </div>
        `;
        
        this.updateElement('course-basic-info', basicInfoHtml);
    }

    /**
     * Display course description
     * @param {string} description 
     */
    displayDescription(description) {
        if (description) {
            const descriptionHtml = `
                <div class="course-description">
                    <h3>Course Description</h3>
                    <p>${description}</p>
                </div>
            `;
            this.updateElement('course-description', descriptionHtml);
        }
    }

    /**
     * Display what you'll learn section
     * @param {Array} learnings 
     */
    displayLearnings(learnings) {
        if (learnings && learnings.length > 0) {
            const learningsHtml = `
                <div class="course-learnings">
                    <h3>What You'll Learn</h3>
                    <ul class="learnings-list">
                        ${learnings.map(learning => `
                            <li class="learning-item">
                                <i class="fas fa-check"></i>
                                <span>${learning.title}</span>
                            </li>
                        `).join('')}
                    </ul>
                </div>
            `;
            this.updateElement('course-learnings', learningsHtml);
        }
    }

    /**
     * Display requirements section
     * @param {Array} requirements 
     */
    displayRequirements(requirements) {
        if (requirements && requirements.length > 0) {
            const requirementsHtml = `
                <div class="course-requirements">
                    <h3>Requirements</h3>
                    <ul class="requirements-list">
                        ${requirements.map(requirement => `
                            <li class="requirement-item">
                                <i class="fas fa-info-circle"></i>
                                <span>${requirement.requirement}</span>
                            </li>
                        `).join('')}
                    </ul>
                </div>
            `;
            this.updateElement('course-requirements', requirementsHtml);
        }
    }

    /**
     * Display course content with chapters
     * @param {Array} courseContent 
     */
    displayCourseContent(courseContent) {
        if (courseContent && courseContent.length > 0) {
            const contentHtml = `
                <div class="course-content">
                    <div class="content-header">
                        <h3>Course Content</h3>
                        <button class="expand-all-btn" onclick="courseManager.toggleAllChapters()">
                            Expand All
                        </button>
                    </div>
                    <div class="chapters-container">
                        ${courseContent.map((chapter, index) => `
                            <div class="chapter-item" data-chapter-id="${chapter.id}">
                                <div class="chapter-header" onclick="courseManager.toggleChapter(${chapter.id})">
                                    <div class="chapter-info">
                                        <span class="chapter-number">${index + 1}.</span>
                                        <span class="chapter-title">${chapter.title}</span>
                                        <span class="chapter-summary">${chapter.summary}</span>
                                    </div>
                                    <div class="chapter-toggle">
                                        <i class="fas fa-chevron-down"></i>
                                    </div>
                                </div>
                                <div class="chapter-content" id="chapter-content-${chapter.id}" style="display: none;">
                                    ${this.renderChapterContent(chapter)}
                                </div>
                            </div>
                        `).join('')}
                    </div>
                </div>
            `;
            this.updateElement('course-content', contentHtml);
        }
    }

    /**
     * Render individual chapter content
     * @param {Object} chapter 
     * @returns {string}
     */
    renderChapterContent(chapter) {
        let content = '';
        
        // Add lectures
        if (chapter.lectures && chapter.lectures.length > 0) {
            content += `
                <div class="lectures-section">
                    <h4>Lectures</h4>
                    ${chapter.lectures.map(lecture => `
                        <div class="lecture-item">
                            <i class="fas fa-play-circle"></i>
                            <span class="lecture-title">${lecture.title}</span>
                            <span class="lecture-duration">${this.formatDuration(lecture.duration)}</span>
                        </div>
                    `).join('')}
                </div>
            `;
        }
        
        // Add assignments
        if (chapter.assignments && chapter.assignments.length > 0) {
            content += `
                <div class="assignments-section">
                    <h4>Assignments</h4>
                    ${chapter.assignments.map(assignment => `
                        <div class="assignment-item">
                            <i class="fas fa-tasks"></i>
                            <span class="assignment-title">${assignment.title}</span>
                        </div>
                    `).join('')}
                </div>
            `;
        }
        
        // Add quizzes
        if (chapter.quizzes && chapter.quizzes.length > 0) {
            content += `
                <div class="quizzes-section">
                    <h4>Quizzes</h4>
                    ${chapter.quizzes.map(quiz => `
                        <div class="quiz-item">
                            <i class="fas fa-question-circle"></i>
                            <span class="quiz-title">${quiz.title}</span>
                        </div>
                    `).join('')}
                </div>
            `;
        }
        
        // Add resources
        if (chapter.resources && chapter.resources.length > 0) {
            content += `
                <div class="resources-section">
                    <h4>Resources</h4>
                    ${chapter.resources.map(resource => `
                        <div class="resource-item">
                            <i class="fas fa-file"></i>
                            <span class="resource-title">${resource.title}</span>
                        </div>
                    `).join('')}
                </div>
            `;
        }
        
        return content;
    }

    /**
     * Display course statistics
     * @param {Object} statistics 
     */
    displayCourseStatistics(statistics) {
        if (statistics) {
            const statsHtml = `
                <div class="course-statistics">
                    <div class="stat-item">
                        <i class="fas fa-play-circle"></i>
                        <span class="stat-value">${statistics.total_lectures}</span>
                        <span class="stat-label">Lectures</span>
                    </div>
                    <div class="stat-item">
                        <i class="fas fa-tasks"></i>
                        <span class="stat-value">${statistics.total_assignments}</span>
                        <span class="stat-label">Assignments</span>
                    </div>
                    <div class="stat-item">
                        <i class="fas fa-question-circle"></i>
                        <span class="stat-value">${statistics.total_quizzes}</span>
                        <span class="stat-label">Quizzes</span>
                    </div>
                    <div class="stat-item">
                        <i class="fas fa-clock"></i>
                        <span class="stat-value">${statistics.total_duration}</span>
                        <span class="stat-label">Duration</span>
                    </div>
                    <div class="stat-item">
                        <i class="fas fa-book"></i>
                        <span class="stat-value">${statistics.total_chapters}</span>
                        <span class="stat-label">Chapters</span>
                    </div>
                </div>
            `;
            this.updateElement('course-statistics', statsHtml);
        }
    }

    /**
     * Toggle chapter expansion
     * @param {number} chapterId 
     */
    toggleChapter(chapterId) {
        const contentElement = document.getElementById(`chapter-content-${chapterId}`);
        const toggleIcon = contentElement.previousElementSibling.querySelector('.chapter-toggle i');
        
        if (contentElement.style.display === 'none') {
            contentElement.style.display = 'block';
            toggleIcon.className = 'fas fa-chevron-up';
        } else {
            contentElement.style.display = 'none';
            toggleIcon.className = 'fas fa-chevron-down';
        }
    }

    /**
     * Toggle all chapters
     */
    toggleAllChapters() {
        const expandBtn = document.querySelector('.expand-all-btn');
        const isExpanded = expandBtn.textContent === 'Collapse All';
        
        const chapterContents = document.querySelectorAll('.chapter-content');
        const toggleIcons = document.querySelectorAll('.chapter-toggle i');
        
        chapterContents.forEach((content, index) => {
            if (isExpanded) {
                content.style.display = 'none';
                toggleIcons[index].className = 'fas fa-chevron-down';
            } else {
                content.style.display = 'block';
                toggleIcons[index].className = 'fas fa-chevron-up';
            }
        });
        
        expandBtn.textContent = isExpanded ? 'Expand All' : 'Collapse All';
    }

    /**
     * Update DOM element content
     * @param {string} elementId 
     * @param {string} html 
     */
    updateElement(elementId, html) {
        const element = document.getElementById(elementId);
        if (element) {
            element.innerHTML = html;
        }
    }

    /**
     * Generate star rating HTML
     * @param {number} rating 
     * @returns {string}
     */
    generateStars(rating) {
        const fullStars = Math.floor(rating);
        const hasHalfStar = rating % 1 !== 0;
        const emptyStars = 5 - fullStars - (hasHalfStar ? 1 : 0);
        
        let stars = '';
        
        // Full stars
        for (let i = 0; i < fullStars; i++) {
            stars += '<i class="fas fa-star"></i>';
        }
        
        // Half star
        if (hasHalfStar) {
            stars += '<i class="fas fa-star-half-alt"></i>';
        }
        
        // Empty stars
        for (let i = 0; i < emptyStars; i++) {
            stars += '<i class="far fa-star"></i>';
        }
        
        return stars;
    }

    /**
     * Format duration from minutes to readable format
     * @param {number} minutes 
     * @returns {string}
     */
    formatDuration(minutes) {
        if (!minutes) return '0 Min';
        
        const hours = Math.floor(minutes / 60);
        const mins = minutes % 60;
        
        if (hours > 0) {
            return `${hours}hr:${mins} Min`;
        }
        return `${mins} Min`;
    }

    /**
     * Show error message
     * @param {string} message 
     */
    showError(message) {
        const errorHtml = `
            <div class="error-message">
                <i class="fas fa-exclamation-triangle"></i>
                <span>${message}</span>
            </div>
        `;
        this.updateElement('course-content', errorHtml);
    }
}

// Initialize the course manager
const courseManager = new CourseDetailsManager();

// Example usage:
// Load course by ID
// courseManager.initCourseDetails({ id: 1 });

// Load course by slug
// courseManager.initCourseDetails({ slug: 'ui-ux-design-course' });

// Export for use in other modules
if (typeof module !== 'undefined' && module.exports) {
    module.exports = CourseDetailsManager;
}
